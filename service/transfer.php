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
 * Coordinate the destination and source adapters using a durable transfer ID.
 */
final class transfer
{
	public function __construct(private transfer_plan $planner)
	{
	}

	/**
	 * The destination callback must return a verified URL and reuse the same
	 * destination on retry. The source callback must also accept retries.
	 */
	public function execute(
		string $transfer_id,
		array $topic_posts,
		array $selected_posts,
		bool $copy_topic,
		string $reason,
		callable $verify_destination,
		callable $apply_source
	): string
	{
		$plan = $this->planner->build($topic_posts, $selected_posts, $copy_topic);

		if ($plan['close'] && trim($reason) === '')
		{
			throw new \InvalidArgumentException('Enter a closing reason.');
		}

		$url = $verify_destination($transfer_id, $plan['transfer']);
		if (!is_string($url) || $url === '')
		{
			throw new \RuntimeException('The destination transfer could not be verified.');
		}

		$apply_source($transfer_id, $plan, $url, $reason);

		return $url;
	}
}
