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

/**
 * Decide what happens to the source once Titania confirms the transfer.
 *
 * The source adapter must supply the complete post list in display order.
 */
final class transfer_plan
{
	/**
	 * @param list<int> $topic_posts
	 * @param list<int> $selected_posts
	 * @return array{transfer: list<int>, remove: list<int>, shadow: bool, close: bool}
	 */
	public function build(array $topic_posts, array $selected_posts, bool $copy_topic = false): array
	{
		if (!$selected_posts || array_diff($selected_posts, $topic_posts))
		{
			throw new \InvalidArgumentException('Select posts from the source topic.');
		}

		$selected = array_fill_keys($selected_posts, true);
		$transfer = array_values(array_intersect_key(array_combine($topic_posts, $topic_posts), $selected));
		$whole_topic = count($transfer) === count($topic_posts);

		if ($copy_topic && !$whole_topic)
		{
			throw new \InvalidArgumentException('Copy and close requires the whole topic.');
		}

		$opening_post = $topic_posts[0];
		$retain_opening = !$whole_topic && isset($selected[$opening_post]);

		return [
			'transfer' => $transfer,
			'remove' => $copy_topic ? [] : array_values(array_diff($transfer, $retain_opening ? [$opening_post] : [])),
			'shadow' => $whole_topic && !$copy_topic,
			'close' => $copy_topic || $retain_opening,
		];
	}
}
