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

use phpbb\exception\http_exception;
use phpbbmodders\titaniatopictransfer\service\forum_posts;
use phpbbmodders\titaniatopictransfer\service\attachments;
use Symfony\Component\HttpFoundation\Response;

final class preview
{
	public function __construct(
		private \phpbb\auth\auth $auth,
		private \phpbb\extension\manager $extensions,
		private \phpbb\controller\helper $helper,
		private \phpbb\template\template $template,
		private \phpbb\language\language $language,
		private \phpbb\db\driver\driver_interface $db,
		private \phpbb\request\request_interface $request,
		private string $root_path
	)
	{
	}

	public function display(int $topic_id): Response
	{
		if (!$this->extensions->is_enabled('phpbb/titania') || !$this->auth->acl_get('u_titania_mod_post_mod'))
		{
			throw new http_exception(403, 'NO_AUTH_OPERATION');
		}

		try
		{
			$source = new forum_posts($this->db, $this->auth);
			$topic = $source->read($topic_id);
			$source->require_public_source((int) $topic['topic']['forum_id'], []);
		}
		catch (\DomainException $error)
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
				$url = $this->submit($source, $topic_id);
				return new \Symfony\Component\HttpFoundation\RedirectResponse($url, 303);
			}
			catch (\DomainException | \InvalidArgumentException $error)
			{
				$this->template->assign_var('TRANSFER_ERROR', $error->getMessage());
			}
		}

		add_form_key('titania_transfer');
		$this->template->assign_var('TRANSFER_TOPIC_TITLE', $topic['topic']['topic_title']);
		foreach ($topic['posts'] as $post_id => $post)
		{
			$this->template->assign_block_vars('transfer_posts', [
				'ID' => $post_id,
				'SUBJECT' => $post['subject'],
				'APPROVED' => $post['approved'],
			]);
		}

		return $this->helper->render('titania_transfer_preview.html', $this->language->lang('TITANIA_TRANSFER'));
	}

	private function submit(forum_posts $source, int $topic_id): string
	{
		$attachments = new attachments();
		$this->db->sql_transaction('begin');
		try
		{
			$url = $this->move_posts($source, $source->read($topic_id), $attachments);
			if (!$this->db->sql_transaction('commit'))
			{
				throw new \RuntimeException('Unable to commit transfer.');
			}
			return $url;
		}
		catch (\Throwable $error)
		{
			$this->db->sql_transaction('rollback');
			$attachments->rollback();
			throw $error;
		}
	}

	private function move_posts(forum_posts $source, array $data, attachments $attachments): string
	{
		$selected = $this->request->variable('posts', [0]);
		$plan = (new \phpbbmodders\titaniatopictransfer\service\transfer_plan())->build(array_keys($data['posts']), $selected, $this->request->variable('copy_topic', false));
		$posts = array_intersect_key($data['posts'], array_fill_keys($plan['transfer'], true));
		$forum = (int) $data['topic']['forum_id'];
		$source->require_public_source($forum, $posts);

		$reason = trim($this->request->variable('reason', '', true));
		if (($plan['remove'] && !$this->auth->acl_get('m_delete', $forum))
			|| (($plan['close'] || $plan['shadow']) && (!$this->auth->acl_get('m_lock', $forum) || !$this->auth->acl_get('f_reply', $forum))))
		{
			throw new http_exception(403, 'NO_AUTH_OPERATION');
		}

		if ($plan['close'] && $reason === '')
		{
			throw new \DomainException('Enter a closing reason.');
		}

		$contribution_id = $this->request->variable('contribution_id', 0);
		$destination_id = $this->request->variable('destination_id', 0);
		if (!class_exists('titania', false))
		{
			require $this->root_path . 'ext/phpbb/titania/common.php';
		}

		$contribution = new \titania_contribution();
		if (!$contribution_id || !$contribution->load($contribution_id) || !$contribution->contrib_visible
			|| (int) $contribution->contrib_status !== \phpbb\titania\ext::TITANIA_CONTRIB_APPROVED)
		{
			throw new \DomainException('Choose an approved public contribution.');
		}

		$destination = null;
		if ($destination_id)
		{
			$destination = new \phpbbmodders\titaniatopictransfer\service\support_topic();
			if (!$destination->load($destination_id) || !$destination->topic_approved || $destination->topic_locked
				|| (int) $destination->topic_access !== \phpbb\titania\access::PUBLIC_LEVEL)
			{
				throw new \DomainException('Choose an unlocked public support topic.');
			}
		}

		require_once $this->root_path . 'includes/functions_admin.php';
		$result = (new \phpbbmodders\titaniatopictransfer\service\titania_posts())->write(
			$contribution, $destination, $data['topic']['topic_title'], $posts
		);
		foreach ($result['posts'] as $source_id => $destination_id)
		{
			if ($posts[$source_id]['has_attachment'])
			{
				$attachments->copy($source_id, $destination_id);
			}
		}
		$url = html_entity_decode($result['topic']->get_url(), ENT_QUOTES);
		$this->verify_posts((int) $result['topic']->topic_id, $result['posts']);
		if (($plan['shadow'] || $this->request->variable('copy_topic', false)) && $url
			&& $this->request->variable('copy_subscriptions', false))
		{
			$subscriptions = \phpbb::$container->get('phpbb.titania.subscriptions');
			$watchers = $this->db->sql_query('SELECT user_id FROM ' . TOPICS_WATCH_TABLE . '
				WHERE topic_id = ' . (int) $data['topic']['topic_id']);
			$rows = $this->db->sql_fetchrowset($watchers);
			$this->db->sql_freeresult($watchers);
			foreach ($rows as $row)
			{
				$subscriptions->subscribe(\phpbb\titania\ext::TITANIA_TOPIC, (int) $result['topic']->topic_id, (int) $row['user_id']);
			}
		}

		if (!$destination && ($plan['shadow'] || $this->request->variable('copy_topic', false))
			&& $this->request->variable('copy_tracking', false))
		{
			$tracking = $this->db->sql_query('SELECT user_id, mark_time FROM ' . TOPICS_TRACK_TABLE . '
				WHERE topic_id = ' . (int) $data['topic']['topic_id']);
			$rows = $this->db->sql_fetchrowset($tracking);
			$this->db->sql_freeresult($tracking);
			foreach ($rows as $row)
			{
				$this->db->sql_query('INSERT INTO ' . \phpbb::$container->getParameter('tables.titania.track') . ' ' . $this->db->sql_build_array('INSERT', [
					'track_type' => \phpbb\titania\ext::TITANIA_TOPIC,
					'track_id' => (int) $result['topic']->topic_id,
					'track_user_id' => (int) $row['user_id'],
					'track_time' => (int) $row['mark_time'],
				]));
			}
		}

		if ($plan['shadow'] && $url)
		{
			$source->close($data['topic'], 'This topic has moved to Titania support.', $url, $this->root_path);
		}

		if (!$url || ($plan['remove'] && delete_posts('post_id', $plan['remove']) !== count($plan['remove'])))
		{
			throw new \RuntimeException('The transfer could not be completed.');
		}
		$this->verify_posts((int) $result['topic']->topic_id, $result['posts']);

		if ($plan['close'])
		{
			$source->close($data['topic'], $reason, $url, $this->root_path);
		}

		return $url;
	}

	private function verify_posts(int $topic_id, array $post_ids): void
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS total FROM ' . TITANIA_POSTS_TABLE . '
			WHERE topic_id = ' . $topic_id . ' AND ' . $this->db->sql_in_set('post_id', array_values($post_ids)));
		$count = (int) $this->db->sql_fetchfield('total', false, $result);
		$this->db->sql_freeresult($result);
		if ($count !== count($post_ids))
		{
			throw new \RuntimeException('Titania post verification failed.');
		}
	}
}
