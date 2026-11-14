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

use phpbb\exception\runtime_exception;
use phpbb\titania\ext;

/**
 * Write normalized forum posts using Titania's support posting lifecycle.
 * The caller owns the transaction and must bootstrap Titania before calling.
 */
final class titania_posts
{
	/**
	 * Write the posts as one Titania support topic, new or existing.
	 *
	 * @param \titania_contribution $contribution Approved contribution the topic belongs to
	 * @param \titania_topic|null   $topic        Existing support topic, or null to start one
	 * @param string                $subject      Subject for a new topic
	 * @param array                 $posts        Posts as returned by forum_posts::read(), in topic order
	 * @return array{topic: \titania_topic, posts: array<int, int>} Titania post IDs keyed by forum post ID
	 * @throws runtime_exception When the destination does not belong to the contribution
	 * @throws \RuntimeException When Titania cannot save a post
	 */
	public function write(\titania_contribution $contribution, \titania_topic|null $topic, string $subject, array $posts): array
	{
		if (!$posts || !$contribution->contrib_id)
		{
			throw new runtime_exception('TITANIATOPICTRANSFER_ERR_NO_SELECTION');
		}

		if ($topic !== null && (!$topic->topic_id || (int) $topic->parent_id !== (int) $contribution->contrib_id
			|| (int) $topic->topic_type !== ext::TITANIA_SUPPORT))
		{
			throw new runtime_exception('TITANIATOPICTRANSFER_ERR_DESTINATION');
		}

		$destination_posts = [];
		foreach ($posts as $source_id => $source)
		{
			$post = new \titania_post(ext::TITANIA_SUPPORT, $topic ?? new support_topic());
			if ($topic === null)
			{
				$post->topic->parent_id = $contribution->contrib_id;
				$post->topic->topic_category = $contribution->contrib_type;
				$post->topic->topic_url = serialize([
					'contrib_type' => $contribution->type->url,
					'contrib' => $contribution->contrib_name_clean,
				]);
			}

			$post->post_attachment = $source['has_attachment'];
			$post->post_subject = $topic === null ? $subject : $source['subject'];
			$post->post_user_id = $source['author_id'];
			$post->post_time = $source['time'];
			$post->post_text = $source['text'];
			$post->post_text_uid = $source['uid'];
			$post->post_text_bitfield = $source['bitfield'];
			$post->post_text_options = $source['options'];
			if ($post->submit() !== true || !$post->post_id)
			{
				throw new \RuntimeException('TITANIATOPICTRANSFER_ERR_FAILED');
			}

			$topic = $post->topic;
			$destination_posts[$source_id] = (int) $post->post_id;
		}

		return ['topic' => $topic, 'posts' => $destination_posts];
	}
}
