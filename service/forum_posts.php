<?php
/**
 *
 * Titania Topic Transfer extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbbmodders\titaniatopictransfer\service;

use phpbb\auth\auth;
use phpbb\db\driver\driver_interface;

/**
 * Read an approved forum topic and normalize its posts for Titania.
 */
final class forum_posts
{
	public function __construct(private driver_interface $db, private auth $auth)
	{
	}

	/**
	 * Ensure the selected posts can be published to a public support topic.
	 *
	 * Check the effective anonymous ACL, including user and group roles.
	 */
	public function require_public_source(int $forum_id, array $selected_posts): void
	{
		$readers = $this->auth->acl_get_list(ANONYMOUS, 'f_read', $forum_id);
		$listers = $this->auth->acl_get_list(ANONYMOUS, 'f_list', $forum_id);

		$result = $this->db->sql_query('SELECT forum_password FROM ' . FORUMS_TABLE . '
			WHERE forum_id = ' . $forum_id);
		$forum = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$forum || $forum['forum_password'] !== ''
			|| !in_array(ANONYMOUS, $readers[$forum_id]['f_read'] ?? [])
			|| !in_array(ANONYMOUS, $listers[$forum_id]['f_list'] ?? []))
		{
			throw new \DomainException('The source forum is restricted.');
		}

		foreach ($selected_posts as $post)
		{
			if (!$post['approved'])
			{
				throw new \DomainException('Only approved posts can be transferred to public support.');
			}
		}
	}

	public function read(int $topic_id): array
	{
		$result = $this->db->sql_query('SELECT topic_id, forum_id, topic_title, topic_first_post_id, topic_visibility, topic_moved_id
			FROM ' . TOPICS_TABLE . '
			WHERE topic_id = ' . $topic_id);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$topic || (int) $topic['topic_visibility'] !== ITEM_APPROVED || $topic['topic_moved_id']
			|| !$this->auth->acl_get('m_move', (int) $topic['forum_id'])
			|| !$this->auth->acl_get('f_read', (int) $topic['forum_id']))
		{
			throw new \DomainException('The source topic is unavailable for transfer.');
		}

		$result = $this->db->sql_query('SELECT post_id, post_subject, poster_id, post_time, post_text, bbcode_uid,
				bbcode_bitfield, enable_bbcode, enable_smilies, enable_magic_url, post_visibility, post_attachment
			FROM ' . POSTS_TABLE . '
			WHERE topic_id = ' . $topic_id . '
			ORDER BY post_time, post_id');
		$posts = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$posts[(int) $row['post_id']] = [
				'subject' => $row['post_subject'],
				'author_id' => (int) $row['poster_id'],
				'time' => (int) $row['post_time'],
				'text' => $row['post_text'],
				'uid' => $row['bbcode_uid'],
				'bitfield' => $row['bbcode_bitfield'],
				'options' => ($row['enable_bbcode'] ? OPTION_FLAG_BBCODE : 0)
					| ($row['enable_smilies'] ? OPTION_FLAG_SMILIES : 0)
					| ($row['enable_magic_url'] ? OPTION_FLAG_LINKS : 0),
				'approved' => (int) $row['post_visibility'] === ITEM_APPROVED,
				'has_attachment' => (bool) $row['post_attachment'],
			];
		}
		$this->db->sql_freeresult($result);

		$opening_id = (int) $topic['topic_first_post_id'];
		if (!isset($posts[$opening_id]))
		{
			throw new \DomainException('The source topic has no opening post.');
		}

		return ['topic' => $topic, 'posts' => [$opening_id => $posts[$opening_id]] + $posts];
	}

	public function close(array $topic, string $reason, string $url, string $root_path): void
	{
		require_once $root_path . 'includes/functions_posting.php';
		$message = $reason . "\n\n" . $url;
		$uid = $bitfield = '';
		$options = 0;
		$subject = $topic['topic_title'];
		generate_text_for_storage($message, $uid, $bitfield, $options, false, true, false);
		$data = [
			'forum_id' => (int) $topic['forum_id'],
			'topic_id' => (int) $topic['topic_id'],
			'topic_title' => $subject,
			'icon_id' => 0,
			'enable_bbcode' => false,
			'enable_smilies' => false,
			'enable_urls' => true,
			'enable_sig' => false,
			'message' => $message,
			'message_md5' => md5($message),
			'bbcode_bitfield' => $bitfield,
			'bbcode_uid' => $uid,
			'post_edit_locked' => 0,
			'force_approved_state' => ITEM_APPROVED,
			'enable_indexing' => true,
			'notify' => false,
			'notify_set' => false,
		];
		// submit_post recreates this marker. A duplicate insert rolls back the
		// enclosing transaction on SQLite.
		global $config, $user;
		if ($config['load_db_track'])
		{
			$this->db->sql_query('DELETE FROM ' . TOPICS_POSTED_TABLE . '
				WHERE topic_id = ' . (int) $topic['topic_id'] . ' AND user_id = ' . (int) $user->data['user_id']);
		}

		$poll = [];
		submit_post('reply', $subject, '', POST_NORMAL, $poll, $data);
		if (empty($data['post_id']))
		{
			throw new \RuntimeException('The closing post could not be saved.');
		}
		$this->db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET topic_status = ' . ITEM_LOCKED . '
			WHERE topic_id = ' . (int) $topic['topic_id']);
	}

	public function notify_author(int $author_id, string $url, string $root_path): void
	{
		global $config, $user;
		if (!$config['allow_privmsg'] || !$this->auth->acl_get('u_sendpm')
			|| $author_id <= ANONYMOUS || $author_id === (int) $user->data['user_id'])
		{
			return;
		}

		$result = $this->db->sql_query('SELECT user_allow_pm, user_type FROM ' . USERS_TABLE . '
			WHERE user_id = ' . $author_id);
		$recipient = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$recipient || !$recipient['user_allow_pm']
			|| !in_array((int) $recipient['user_type'], [USER_NORMAL, USER_FOUNDER], true))
		{
			return;
		}

		require_once $root_path . 'includes/functions_privmsgs.php';
		$message = "Posts from your forum topic were transferred to Titania support.\n\n" . $url;
		$uid = $bitfield = '';
		$options = 0;
		generate_text_for_storage($message, $uid, $bitfield, $options, false, true, false);
		$data = [
			'from_user_id' => (int) $user->data['user_id'],
			'from_user_ip' => $user->ip,
			'from_username' => $user->data['username'],
			'address_list' => ['u' => [$author_id => 'to']],
			'icon_id' => 0,
			'enable_bbcode' => false,
			'enable_smilies' => false,
			'enable_urls' => true,
			'enable_sig' => false,
			'bbcode_uid' => $uid,
			'bbcode_bitfield' => $bitfield,
			'message' => $message,
		];
		submit_pm('post', 'Forum posts transferred to Titania support', $data);
	}
}
