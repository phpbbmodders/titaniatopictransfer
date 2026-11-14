<?php

namespace phpbbmodders\titaniatopictransfer\controller;

use phpbb\exception\http_exception;
use phpbbmodders\titaniatopictransfer\service\forum_posts;
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
		$this->db->sql_transaction('begin');
		try
		{
			$url = $this->move_replies($source, $source->read($topic_id));
			if (!$this->db->sql_transaction('commit'))
			{
				throw new \RuntimeException('Unable to commit transfer.');
			}
			return $url;
		}
		catch (\Throwable $error)
		{
			$this->db->sql_transaction('rollback');
			throw $error;
		}
	}

	private function move_replies(forum_posts $source, array $data): string
	{
		$selected = $this->request->variable('posts', [0]);
		$plan = (new \phpbbmodders\titaniatopictransfer\service\transfer_plan())->build(array_keys($data['posts']), $selected);
		$posts = array_intersect_key($data['posts'], array_fill_keys($plan['transfer'], true));
		$forum = (int) $data['topic']['forum_id'];
		$source->require_public_source($forum, $posts);

		if ($plan['shadow'] || $plan['close'])
		{
			throw new \DomainException('Select replies while leaving the opening post in the forum.');
		}

		if (!$this->auth->acl_get('m_delete', $forum))
		{
			throw new http_exception(403, 'NO_AUTH_OPERATION');
		}

		foreach ($posts as $post)
		{
			if ($post['has_attachment'])
			{
				throw new \DomainException('Posts with attachments cannot be transferred yet.');
			}
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
		$url = html_entity_decode($result['topic']->get_url(), ENT_QUOTES);
		$this->verify_posts((int) $result['topic']->topic_id, $result['posts']);
		if (!$url || delete_posts('post_id', $plan['remove']) !== count($plan['remove']))
		{
			throw new \RuntimeException('The transfer could not be completed.');
		}
		$this->verify_posts((int) $result['topic']->topic_id, $result['posts']);

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
