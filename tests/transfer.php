<?php
/**
 *
 * Titania Topic Transfer extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

require __DIR__ . '/../service/transfer_plan.php';
require __DIR__ . '/../service/transfer.php';

use phpbbmodders\titaniatopictransfer\service\transfer;
use phpbbmodders\titaniatopictransfer\service\transfer_plan;

$service = new transfer(new transfer_plan());
$applied = [];
$apply_source = static function (...$arguments) use (&$applied): void {
	$applied[] = $arguments;
};

try
{
	$service->execute('fixture', [10, 20, 30], [30, 10], false, 'Moved to support',
		static fn () => null, $apply_source);
	throw new LogicException('An unverified destination was accepted.');
}
catch (RuntimeException $error)
{
	if ($error->getMessage() !== 'The destination transfer could not be verified.' || $applied)
	{
		throw $error;
	}
}

$url = '/db/extension/fixture/support/topic/42';
$result = $service->execute('fixture', [10, 20, 30], [30, 10], false, 'Moved to support',
	static function (string $id, array $posts) use ($url): string {
		if ($id !== 'fixture' || $posts !== [10, 30])
		{
			throw new LogicException('The destination received unexpected posts.');
		}
		return $url;
	}, $apply_source);

if ($result !== $url || $applied !== [['fixture', [
	'transfer' => [10, 30],
	'remove' => [30],
	'shadow' => false,
	'close' => true,
], $url, 'Moved to support']])
{
	throw new LogicException('The source received an unexpected closing operation.');
}

echo "Transfer coordination checks passed.\n";
