<?php

namespace phpbbmodders\titaniatopictransfer\service;

use phpbb\titania\ext;

/**
 * Write normalized forum posts using Titania's support posting lifecycle.
 * The caller owns the transaction and must bootstrap Titania before calling.
 */
final class titania_posts
{
	public function write(\titania_contribution $contribution, ?\titania_topic $topic, string $subject, array $posts): array
	{
		if (!$posts || !$contribution->contrib_id)
		{
			throw new \InvalidArgumentException('A contribution and at least one post are required.');
		}

		if ($topic !== null && (!$topic->topic_id || (int) $topic->parent_id !== (int) $contribution->contrib_id
			|| (int) $topic->topic_type !== ext::TITANIA_SUPPORT))
		{
			throw new \InvalidArgumentException('The destination must be a support topic in the selected contribution.');
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

			$post->post_subject = $topic === null ? $subject : $source['subject'];
			$post->post_user_id = $source['author_id'];
			$post->post_time = $source['time'];
			$post->post_text = $source['text'];
			$post->post_text_uid = $source['uid'];
			$post->post_text_bitfield = $source['bitfield'];
			$post->post_text_options = $source['options'];
			if ($post->submit() !== true || !$post->post_id)
			{
				throw new \RuntimeException('Titania could not save the support post.');
			}

			$topic = $post->topic;
			$destination_posts[$source_id] = (int) $post->post_id;
		}

		return ['topic' => $topic, 'posts' => $destination_posts];
	}
}
