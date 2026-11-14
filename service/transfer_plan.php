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

/**
 * Decide what happens to the forum topic once Titania confirms the transfer.
 */
final class transfer_plan
{
	/**
	 * Build the source plan for a selection of posts.
	 *
	 * @param list<int> $topic_posts    Every visible post of the topic, opening post first
	 * @param list<int> $selected_posts Posts the moderator selected
	 * @param bool      $copy_topic     Copy the whole topic and close the original
	 * @return array{transfer: list<int>, remove: list<int>, shadow: bool, close: bool}
	 *         transfer: posts to write to Titania, in topic order;
	 *         remove: forum posts to delete afterwards;
	 *         shadow: leave a locked topic with a link (whole-topic move);
	 *         close: lock the topic with the moderator's reason
	 * @throws runtime_exception When the selection is empty, foreign, or not a whole topic for a copy
	 */
	public function build(array $topic_posts, array $selected_posts, bool $copy_topic = false): array
	{
		if (!$topic_posts || !$selected_posts || array_diff($selected_posts, $topic_posts))
		{
			throw new runtime_exception('TITANIATOPICTRANSFER_ERR_NO_SELECTION');
		}

		$selected = array_fill_keys($selected_posts, true);
		$transfer = array_values(array_intersect_key(array_combine($topic_posts, $topic_posts), $selected));
		$whole_topic = count($transfer) === count($topic_posts);

		if ($copy_topic && !$whole_topic)
		{
			throw new runtime_exception('TITANIATOPICTRANSFER_ERR_COPY_WHOLE_TOPIC');
		}

		$opening_post = $topic_posts[0];
		$retain_opening = !$whole_topic && isset($selected[$opening_post]);

		return [
			'transfer'	=> $transfer,
			'remove'	=> $copy_topic ? [] : array_values(array_diff($transfer, $retain_opening ? [$opening_post] : [])),
			'shadow'	=> $whole_topic && !$copy_topic,
			'close'		=> $copy_topic || $retain_opening,
		];
	}
}
