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
}
