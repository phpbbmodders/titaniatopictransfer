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
use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\exception\runtime_exception;
use phpbb\language\language;
use phpbb\user;

/**
 * Read a forum topic for a transfer, and change the forum side afterwards:
 * the closing reply, the topic lock and the message to the topic starter.
 */
final class forum_posts
{
	public function __construct(
		private driver_interface $db,
		private auth $auth,
		private config $config,
		private user $user,
		private language $language,
		private string $root_path,
		private string $php_ext
	)
	{
	}

	/**
	 * Ensure the selected posts can be published to a public support topic:
	 * guests must be able to see and read the forum, it has no password,
	 * and every selected post is approved.
	 *
	 * @param int   $forum_id       Forum of the source topic
	 * @param array $selected_posts Posts as returned by read()
	 * @throws runtime_exception When the forum or a post is not public
	 */
	public function require_public_source(int $forum_id, array $selected_posts): void
	{
		$readers = $this->auth->acl_get_list(ANONYMOUS, 'f_read', $forum_id);
		$listers = $this->auth->acl_get_list(ANONYMOUS, 'f_list', $forum_id);

		$sql = 'SELECT forum_password
			FROM ' . FORUMS_TABLE . '
			WHERE forum_id = ' . (int) $forum_id;
		$result = $this->db->sql_query($sql);
		$forum = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$forum || $forum['forum_password'] !== ''
			|| !in_array(ANONYMOUS, $readers[$forum_id]['f_read'] ?? [])
			|| !in_array(ANONYMOUS, $listers[$forum_id]['f_list'] ?? []))
		{
			throw new runtime_exception('TITANIATOPICTRANSFER_ERR_SOURCE_RESTRICTED');
		}

		foreach ($selected_posts as $post)
		{
			if (!$post['approved'])
			{
				throw new runtime_exception('TITANIATOPICTRANSFER_ERR_UNAPPROVED');
			}
		}
	}

	/**
	 * Read an approved topic the current user may move, with all of its posts.
	 *
	 * @param int $topic_id Forum topic ID
	 * @return array{topic: array, posts: array<int, array>} Posts keyed by post ID, opening post first
	 * @throws runtime_exception When the topic is missing, moved, unapproved or not movable by this user
	 */
	public function read(int $topic_id): array
	{
		$sql = 'SELECT topic_id, forum_id, topic_title, topic_first_post_id, topic_visibility, topic_moved_id
			FROM ' . TOPICS_TABLE . '
			WHERE topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$topic || (int) $topic['topic_visibility'] !== ITEM_APPROVED || $topic['topic_moved_id']
			|| !$this->auth->acl_get('m_move', (int) $topic['forum_id'])
			|| !$this->auth->acl_get('f_read', (int) $topic['forum_id']))
		{
			throw new runtime_exception('TITANIATOPICTRANSFER_ERR_TOPIC_UNAVAILABLE');
		}

		$sql = $this->db->sql_build_query('SELECT', [
			'SELECT'	=> 'p.post_id, p.post_subject, p.poster_id, p.post_username, p.post_time, p.post_text, p.bbcode_uid,
				p.bbcode_bitfield, p.enable_bbcode, p.enable_smilies, p.enable_magic_url, p.post_visibility, p.post_attachment,
				u.username',
			'FROM'		=> [POSTS_TABLE => 'p'],
			'LEFT_JOIN'	=> [
				[
					'FROM'	=> [USERS_TABLE => 'u'],
					'ON'	=> 'u.user_id = p.poster_id',
				],
			],
			'WHERE'		=> 'p.topic_id = ' . (int) $topic_id,
			'ORDER_BY'	=> 'p.post_time, p.post_id',
		]);
		$result = $this->db->sql_query($sql);
		$posts = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$posts[(int) $row['post_id']] = [
				'subject'			=> $row['post_subject'],
				'author_id'			=> (int) $row['poster_id'],
				'author_name'		=> (int) $row['poster_id'] === ANONYMOUS ? $row['post_username'] : (string) $row['username'],
				'time'				=> (int) $row['post_time'],
				'text'				=> $row['post_text'],
				'uid'				=> $row['bbcode_uid'],
				'bitfield'			=> $row['bbcode_bitfield'],
				'options'			=> ($row['enable_bbcode'] ? OPTION_FLAG_BBCODE : 0)
					| ($row['enable_smilies'] ? OPTION_FLAG_SMILIES : 0)
					| ($row['enable_magic_url'] ? OPTION_FLAG_LINKS : 0),
				'approved'			=> (int) $row['post_visibility'] === ITEM_APPROVED,
				'has_attachment'	=> (bool) $row['post_attachment'],
			];
		}
		$this->db->sql_freeresult($result);

		$opening_id = (int) $topic['topic_first_post_id'];
		if (!isset($posts[$opening_id]))
		{
			throw new runtime_exception('TITANIATOPICTRANSFER_ERR_NO_OPENING_POST');
		}

		return ['topic' => $topic, 'posts' => [$opening_id => $posts[$opening_id]] + $posts];
	}

	/**
	 * Post a closing reply with a link to the Titania topic, then lock the topic.
	 *
	 * @param array  $topic  Topic row as returned by read()
	 * @param string $reason Text of the closing reply, already localised
	 * @param string $url    Titania support topic URL
	 * @throws \RuntimeException When the reply could not be saved
	 */
	public function close(array $topic, string $reason, string $url): void
	{
		if (!function_exists('submit_post'))
		{
			require_once $this->root_path . 'includes/functions_posting.' . $this->php_ext;
		}

		$message = $reason . "\n\n" . $url;
		$uid = $bitfield = '';
		$options = 0;
		$subject = $topic['topic_title'];
		generate_text_for_storage($message, $uid, $bitfield, $options, false, true, false);
		$data = [
			'forum_id'				=> (int) $topic['forum_id'],
			'topic_id'				=> (int) $topic['topic_id'],
			'topic_title'			=> $subject,
			'icon_id'				=> 0,
			'enable_bbcode'			=> false,
			'enable_smilies'		=> false,
			'enable_urls'			=> true,
			'enable_sig'			=> false,
			'message'				=> $message,
			'message_md5'			=> md5($message),
			'bbcode_bitfield'		=> $bitfield,
			'bbcode_uid'			=> $uid,
			'post_edit_locked'		=> 0,
			'force_approved_state'	=> ITEM_APPROVED,
			'enable_indexing'		=> true,
			'notify'				=> false,
			'notify_set'			=> false,
		];

		// submit_post() adds this "posted in topic" marker again; a duplicate
		// insert fails and rolls back the whole transfer on some databases.
		if ($this->config['load_db_track'])
		{
			$sql = 'DELETE FROM ' . TOPICS_POSTED_TABLE . '
				WHERE topic_id = ' . (int) $topic['topic_id'] . '
					AND user_id = ' . (int) $this->user->data['user_id'];
			$this->db->sql_query($sql);
		}

		$poll = [];
		submit_post('reply', $subject, '', POST_NORMAL, $poll, $data);
		if (empty($data['post_id']))
		{
			throw new \RuntimeException('TITANIATOPICTRANSFER_ERR_FAILED');
		}

		$sql = 'UPDATE ' . TOPICS_TABLE . '
			SET ' . $this->db->sql_build_array('UPDATE', ['topic_status' => ITEM_LOCKED]) . '
			WHERE topic_id = ' . (int) $topic['topic_id'];
		$this->db->sql_query($sql);
	}

	/**
	 * Tell the topic starter by private message where their posts went.
	 * Does nothing when PMs are off, the starter is a guest, a bot, inactive,
	 * the moderator, or does not accept private messages.
	 *
	 * @param int    $author_id Topic starter's user ID
	 * @param string $url       Titania support topic URL
	 */
	public function notify_author(int $author_id, string $url): void
	{
		if (!$this->config['allow_privmsg'] || !$this->auth->acl_get('u_sendpm')
			|| $author_id <= ANONYMOUS || $author_id === (int) $this->user->data['user_id'])
		{
			return;
		}

		$sql = 'SELECT user_allow_pm, user_type
			FROM ' . USERS_TABLE . '
			WHERE user_id = ' . (int) $author_id;
		$result = $this->db->sql_query($sql);
		$recipient = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$recipient || !$recipient['user_allow_pm']
			|| !in_array((int) $recipient['user_type'], [USER_NORMAL, USER_FOUNDER], true))
		{
			return;
		}

		if (!function_exists('submit_pm'))
		{
			require_once $this->root_path . 'includes/functions_privmsgs.' . $this->php_ext;
		}

		$message = $this->language->lang('TITANIATOPICTRANSFER_PM_MESSAGE') . "\n\n" . $url;
		$uid = $bitfield = '';
		$options = 0;
		generate_text_for_storage($message, $uid, $bitfield, $options, false, true, false);
		$data = [
			'from_user_id'		=> (int) $this->user->data['user_id'],
			'from_user_ip'		=> $this->user->ip,
			'from_username'		=> $this->user->data['username'],
			'address_list'		=> ['u' => [$author_id => 'to']],
			'icon_id'			=> 0,
			'enable_bbcode'		=> false,
			'enable_smilies'	=> false,
			'enable_urls'		=> true,
			'enable_sig'		=> false,
			'bbcode_uid'		=> $uid,
			'bbcode_bitfield'	=> $bitfield,
			'message'			=> $message,
		];
		submit_pm('post', $this->language->lang('TITANIATOPICTRANSFER_PM_SUBJECT'), $data);
	}
}
