<?php
/**
 *
 * Titania Topic Transfer extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbbmodders\titaniatopictransfer\controller;

use phpbb\auth\auth;
use phpbb\controller\helper;
use phpbb\db\driver\driver_interface;
use phpbb\exception\http_exception;
use phpbb\exception\runtime_exception;
use phpbb\extension\manager;
use phpbb\language\language;
use phpbb\log\log_interface;
use phpbb\request\request_interface;
use phpbb\template\template;
use phpbb\user;
use phpbbmodders\titaniatopictransfer\service\attachments;
use phpbbmodders\titaniatopictransfer\service\forum_posts;
use phpbbmodders\titaniatopictransfer\service\titania_posts;
use phpbbmodders\titaniatopictransfer\service\transfer_plan;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The transfer page reached from a topic's Quick-mod tools: shows the topic's
 * posts and, on submit, writes them to Titania and changes the forum topic.
 */
final class preview
{
	public function __construct(
		private auth $auth,
		private manager $extensions,
		private helper $helper,
		private template $template,
		private language $language,
		private driver_interface $db,
		private request_interface $request,
		private log_interface $log,
		private user $user,
		private forum_posts $source,
		private attachments $attachments,
		private titania_posts $titania_posts,
		private transfer_plan $planner,
		private ContainerInterface $container,
		private string $root_path,
		private string $php_ext
	)
	{
	}

	/**
	 * Show the transfer form for a topic and run the transfer on submit.
	 *
	 * @param int $topic_id Forum topic ID from the route
	 * @return Response The form, or a redirect to the Titania topic after a transfer
	 * @throws http_exception 403 when Titania is off or the user may not move this topic
	 */
	public function display(int $topic_id): Response
	{
		if (!$this->extensions->is_enabled('phpbb/titania') || !$this->auth->acl_get('u_titania_mod_post_mod'))
		{
			throw new http_exception(403, 'NO_AUTH_OPERATION');
		}

		try
		{
			$topic = $this->source->read($topic_id);
			$this->source->require_public_source((int) $topic['topic']['forum_id'], []);
		}
		catch (runtime_exception $error)
		{
			throw new http_exception(403, 'NO_AUTH_OPERATION');
		}

		$this->language->add_lang('transfer', 'phpbbmodders/titaniatopictransfer');
		if ($this->request->is_set_post('submit'))
		{
			if (!check_form_key('titania_transfer'))
			{
				throw new http_exception(403, 'FORM_INVALID');
			}

			try
			{
				return new RedirectResponse($this->submit($topic_id), 303);
			}
			catch (http_exception $error)
			{
				throw $error;
			}
			catch (runtime_exception $error)
			{
				$this->template->assign_var('TRANSFER_ERROR', $this->language->lang($error->getMessage(), ...$error->get_parameters()));
			}
			catch (\RuntimeException $error)
			{
				$this->template->assign_var('TRANSFER_ERROR', $this->language->lang('TITANIATOPICTRANSFER_ERR_FAILED'));
			}
		}

		add_form_key('titania_transfer');
		$this->template->assign_vars([
			'TRANSFER_TOPIC_TITLE'	=> $topic['topic']['topic_title'],
			'U_TRANSFER_TOPIC'		=> append_sid($this->root_path . 'viewtopic.' . $this->php_ext, 't=' . $topic_id),
			'S_TRANSFER_ACTION'		=> $this->helper->route('phpbbmodders_titaniatopictransfer_transfer', ['topic_id' => $topic_id]),
		]);
		foreach ($topic['posts'] as $post_id => $post)
		{
			if ($post['approved'])
			{
				$this->template->assign_block_vars('transfer_posts', [
					'ID'		=> $post_id,
					'LABEL'		=> $this->language->lang('TITANIATOPICTRANSFER_POST_BY', $post['subject'], $post['author_name']),
				]);
			}
		}

		return $this->helper->render('titania_transfer_preview.html', $this->language->lang('TITANIATOPICTRANSFER'));
	}

	/**
	 * Run a transfer in one database transaction and undo copied files on failure.
	 *
	 * @param int $topic_id Forum topic ID
	 * @return string Titania support topic URL
	 */
	private function submit(int $topic_id): string
	{
		$this->db->sql_transaction('begin');
		try
		{
			$data = $this->source->read($topic_id);
			$url = $this->move_posts($data);
			if (!$this->db->sql_transaction('commit'))
			{
				throw new \RuntimeException('TITANIATOPICTRANSFER_ERR_FAILED');
			}
		}
		catch (\Throwable $error)
		{
			$this->db->sql_transaction('rollback');
			$this->attachments->rollback();
			throw $error;
		}

		$this->log->add('mod', (int) $this->user->data['user_id'], $this->user->ip, 'LOG_TITANIATOPICTRANSFER', false, [
			'forum_id'	=> (int) $data['topic']['forum_id'],
			'topic_id'	=> $topic_id,
			$data['topic']['topic_title'],
			$url,
		]);

		if ($this->request->variable('notify_author', false))
		{
			$author = reset($data['posts']);
			$this->source->notify_author($author['author_id'], $url);
		}

		return $url;
	}

	/**
	 * Write the selected posts to Titania, check them, then change the forum topic.
	 *
	 * @param array $data Topic and posts as returned by forum_posts::read()
	 * @return string Titania support topic URL
	 */
	private function move_posts(array $data): string
	{
		$topic_id = (int) $data['topic']['topic_id'];
		$forum_id = (int) $data['topic']['forum_id'];
		$copy_topic = $this->request->variable('copy_topic', false);

		// Unapproved and soft-deleted posts are not offered and stay in the forum topic.
		$visible_posts = array_keys(array_filter($data['posts'], static fn (array $post): bool => $post['approved']));
		$plan = $this->planner->build($visible_posts, $this->request->variable('posts', [0]), $copy_topic);
		$posts = array_intersect_key($data['posts'], array_flip($plan['transfer']));
		$this->source->require_public_source($forum_id, $posts);

		if (($plan['remove'] && !$this->auth->acl_get('m_delete', $forum_id))
			|| (($plan['close'] || $plan['shadow']) && (!$this->auth->acl_get('m_lock', $forum_id) || !$this->auth->acl_get('f_reply', $forum_id))))
		{
			throw new http_exception(403, 'NO_AUTH_OPERATION');
		}

		$reason = trim($this->request->variable('reason', '', true));
		if ($plan['close'] && $reason === '')
		{
			throw new runtime_exception('TITANIATOPICTRANSFER_ERR_REASON');
		}

		if (!class_exists('titania', false))
		{
			require $this->root_path . 'ext/phpbb/titania/common.' . $this->php_ext;
		}

		$contribution_id = $this->request->variable('contribution_id', 0);
		$contribution = new \titania_contribution();
		if (!$contribution_id || !$contribution->load($contribution_id) || !$contribution->contrib_visible
			|| (int) $contribution->contrib_status !== \phpbb\titania\ext::TITANIA_CONTRIB_APPROVED)
		{
			throw new runtime_exception('TITANIATOPICTRANSFER_ERR_CONTRIB');
		}

		$destination = null;
		$destination_id = $this->request->variable('destination_id', 0);
		if ($destination_id)
		{
			$destination = new \phpbbmodders\titaniatopictransfer\service\support_topic();
			if (!$destination->load($destination_id) || !$destination->topic_approved || $destination->topic_locked
				|| (int) $destination->topic_access !== \phpbb\titania\access::PUBLIC_LEVEL)
			{
				throw new runtime_exception('TITANIATOPICTRANSFER_ERR_DESTINATION');
			}
		}

		if (!function_exists('delete_posts'))
		{
			require_once $this->root_path . 'includes/functions_admin.' . $this->php_ext;
		}

		$result = $this->titania_posts->write($contribution, $destination, $data['topic']['topic_title'], $posts);
		foreach ($result['posts'] as $source_id => $titania_post_id)
		{
			if ($posts[$source_id]['has_attachment'])
			{
				$this->attachments->copy($source_id, $titania_post_id);
			}
		}

		$titania_topic_id = (int) $result['topic']->topic_id;
		$url = html_entity_decode($result['topic']->get_url(), ENT_QUOTES);
		if ($url === '')
		{
			throw new \RuntimeException('TITANIATOPICTRANSFER_ERR_FAILED');
		}
		$this->verify_posts($titania_topic_id, $result['posts']);

		$whole_topic = $plan['shadow'] || $copy_topic;
		if ($whole_topic && $this->request->variable('copy_subscriptions', false))
		{
			$this->copy_subscriptions($topic_id, $titania_topic_id);
		}

		if ($whole_topic && !$destination && $this->request->variable('copy_tracking', false))
		{
			$this->copy_tracking($topic_id, $titania_topic_id);
		}

		if ($plan['shadow'])
		{
			$this->source->close($data['topic'], $this->language->lang('TITANIATOPICTRANSFER_MOVED_NOTICE'), $url);
		}

		if ($plan['remove'] && delete_posts('post_id', $plan['remove']) !== count($plan['remove']))
		{
			throw new \RuntimeException('TITANIATOPICTRANSFER_ERR_FAILED');
		}
		$this->verify_posts($titania_topic_id, $result['posts']);

		if ($plan['close'])
		{
			$this->source->close($data['topic'], $reason, $url);
		}

		return $url;
	}

	/**
	 * Subscribe the forum topic's watchers to the Titania topic.
	 *
	 * @param int $topic_id         Forum topic ID
	 * @param int $titania_topic_id Titania topic ID
	 */
	private function copy_subscriptions(int $topic_id, int $titania_topic_id): void
	{
		$subscriptions = $this->container->get('phpbb.titania.subscriptions');

		$sql = 'SELECT user_id
			FROM ' . TOPICS_WATCH_TABLE . '
			WHERE topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		foreach ($rows as $row)
		{
			$subscriptions->subscribe(\phpbb\titania\ext::TITANIA_TOPIC, $titania_topic_id, (int) $row['user_id']);
		}
	}

	/**
	 * Copy the forum topic's read markers to a new Titania topic.
	 *
	 * @param int $topic_id         Forum topic ID
	 * @param int $titania_topic_id Titania topic ID
	 */
	private function copy_tracking(int $topic_id, int $titania_topic_id): void
	{
		$sql = 'SELECT user_id, mark_time
			FROM ' . TOPICS_TRACK_TABLE . '
			WHERE topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		$markers = [];
		foreach ($rows as $row)
		{
			$markers[] = [
				'track_type'	=> \phpbb\titania\ext::TITANIA_TOPIC,
				'track_id'		=> $titania_topic_id,
				'track_user_id'	=> (int) $row['user_id'],
				'track_time'	=> (int) $row['mark_time'],
			];
		}

		if ($markers)
		{
			$this->db->sql_multi_insert($this->container->getParameter('tables.titania.track'), $markers);
		}
	}

	/**
	 * Check that every written post exists in the Titania topic.
	 *
	 * @param int            $titania_topic_id Titania topic ID
	 * @param array<int,int> $post_ids         Titania post IDs keyed by forum post ID
	 * @throws \RuntimeException When a post is missing
	 */
	private function verify_posts(int $titania_topic_id, array $post_ids): void
	{
		$sql = 'SELECT COUNT(post_id) AS total
			FROM ' . TITANIA_POSTS_TABLE . '
			WHERE topic_id = ' . (int) $titania_topic_id . '
				AND ' . $this->db->sql_in_set('post_id', array_values($post_ids));
		$result = $this->db->sql_query($sql);
		$count = (int) $this->db->sql_fetchfield('total', false, $result);
		$this->db->sql_freeresult($result);

		if ($count !== count($post_ids))
		{
			throw new \RuntimeException('TITANIATOPICTRANSFER_ERR_FAILED');
		}
	}
}
