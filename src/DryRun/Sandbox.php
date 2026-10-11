<?php
/**
 * A throwaway copy of the licensing and order code.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\DryRun;

use Devllo\WPLicenseIt\Licenses\KeyGenerator;
use Devllo\WPLicenseIt\Licenses\LicenseService;
use Devllo\WPLicenseIt\WooCommerce\OrderProcessor;

/**
 * The real license service and order processor, wired to in-memory storage. Whatever is done through it
 * disappears when the object does: nothing is written to the database, to WooCommerce, or sent by email.
 * The test purchase uses it to try a plan without leaving anything behind.
 */
final class Sandbox {

	/**
	 * @var MemoryLicenseRepository
	 */
	public $license_rows;

	/**
	 * @var MemoryActivationRepository
	 */
	public $activations;

	/**
	 * @var MemoryEventLog
	 */
	public $events;

	/**
	 * @var MemoryLicenseOrderRepository
	 */
	public $orders;

	/**
	 * @var MemoryItemLicenseStore
	 */
	public $links;

	/**
	 * @var LicenseService
	 */
	private $licenses;

	/**
	 * @var OrderProcessor
	 */
	private $processor;

	/**
	 * Constructor.
	 *
	 * @param callable|null $clock Returns the current UTC DateTimeImmutable. Defaults to the real time.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->license_rows = new MemoryLicenseRepository();
		$this->activations  = new MemoryActivationRepository();
		$this->events       = new MemoryEventLog();
		$this->orders       = new MemoryLicenseOrderRepository();
		$this->links        = new MemoryItemLicenseStore();

		$this->licenses  = new LicenseService( $this->license_rows, $this->activations, $this->events, new KeyGenerator(), $clock );
		$this->processor = new OrderProcessor( $this->licenses, $this->orders, $this->links, $clock );
	}

	/**
	 * The license service.
	 */
	public function licenses(): LicenseService {
		return $this->licenses;
	}

	/**
	 * The order processor, the same code that turns a paid order into licenses.
	 */
	public function processor(): OrderProcessor {
		return $this->processor;
	}
}
