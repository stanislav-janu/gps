<?php
declare(strict_types=1);

namespace JCode\GPS;

use Nette\Utils\DateTime;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Nette\Utils\Strings;
use Stringable;


/**
 * @property float $lat
 * @property float $lng
 * @property string|null $address
 */
class GpsPoint implements Stringable
{
	public function __construct(
		public float $lat,
		public float $lng,
		public ?string $address = null,
	) {
	}


	/**
	 * @description
	 * Accepted formats:
	 *     49.0518417N, 14.4354897E
	 *     49.0518417N,14.4354897E
	 *     49.0518417, 14.4354897
	 *     49.0518417,14.4354897
	 *     49.0518417 14.4354897
	 *     -47.338388,-0.990228
	 *     -47.338388 -0.990228
	 *     49°3'6.630"N, 14°26'7.763"E
	 *     N 49°3.11050', E 14°26.12938'
	 *     Google maps URL
	 *     Mapy.cz URL
	 */
	public static function from(string $string): self
	{
		/**
		 * Matching decimals:
		 *     49.0518417N, 14.4354897E
		 *     49.0518417N 14.4354897E
		 *     49.0518417, 14.4354897
		 *     49.0518417 14.4354897
		 *     -47.338388,-0.990228
		 *     -47.338388 -0.990228
		 */
		$match = Strings::match($string, '/^(-?[1-8]?\d(?:\.\d{1,18})?|90(?:\.0{1,18})?)N?,?\s*?(-?(?:1[0-7]|[1-9])?\d(?:\.\d{1,18})?|180(?:\.0{1,18})?)E?$/');
		if (is_array($match)) {
			$lat = (float) ($match[1]);
			$lng = (float) ($match[2]);

			return new self($lat, $lng);
		}

		/**
		 * Matching degrees:
		 *     49°3'6.630"N, 14°26'7.763"E
		 */
		$match = Strings::match($string, '/^([0-8]?\d|90)°\s?([0-5]?\d\')?\s?(\d+(?:\.\d{1,5})")?N?,?\s?(1[0-7]?\d|180)°\s?([0-5]?\d\')?\s?(\d+(?:\.\d{1,5})")?E?$/');
		if (is_array($match) && count($match) === 7) {
			$latDeg = (int) ($match[1]);
			$latMin = (int) ($match[2]);
			$latSec = (float) ($match[3]);

			$lngDeg = (int) ($match[4]);
			$lngMin = (int) ($match[5]);
			$lngSec = (float) ($match[6]);

			$lat = $latDeg + ((($latMin * 60) + ($latSec)) / DateTime::HOUR);
			$lng = $lngDeg + ((($lngMin * 60) + ($lngSec)) / DateTime::HOUR);

			return new self(round($lat, 7), round($lng, 7));
		}

		/**
		 * Matching degrees:
		 *     N 49°3.11050', E 14°26.12938'
		 */
		$match = Strings::match($string, '/^N?\s?([0-8]?\d|90)°\s?(\d+(?:\.\d{1,5})\'),?\s?E?\s?(1[0-7]?\d|180)°\s?(\d+(?:\.\d{1,5})\')$/');
		if (is_array($match) && count($match) === 5) {
			$latDeg = (int) ($match[1]);
			$latMin = (float) ($match[2]);

			$lngDeg = (int) ($match[3]);
			$lngMin = (float) ($match[4]);

			$lat = $latDeg + ($latMin * 60 / DateTime::HOUR);
			$lng = $lngDeg + ($lngMin * 60 / DateTime::HOUR);

			return new self(round($lat, 7), round($lng, 7));
		}

		/**
		 * Google maps URL
		 */
		$match = Strings::match($string, '/@([0-9\.]+),([0-9\.]+),([0-9z]+)/');
		if (is_array($match)) {
			$lat = (float) ($match[1]);
			$lng = (float) ($match[2]);

			return new self($lat, $lng);
		}

		/**
		 * Mapy.cz URL
		 */
		$match = Strings::match($string, '/x=([0-9\.]+)&y=([0-9\.]+)&z=(\d+)/');
		if (is_array($match)) {
			$lat = (float) ($match[2]);
			$lng = (float) ($match[1]);

			return new self($lat, $lng);
		}

		throw new GpsPointException('Nothing detected in ' . $string);
	}


	public function __toString(): string
	{
		return str_replace(',', '.', (string) $this->lat) . ',' . str_replace(',', '.', (string) $this->lng);
	}


	/**
	 * Return float in meters
	 */
	public function distanceTo(self $point, string $google_api_key = null): float
	{
		try {
			$url = 'https://maps.googleapis.com/maps/api/distancematrix/json?origins=' . str_replace(',', '.', (string) $this->lat) . ',' . str_replace(',', '.', (string) $this->lng) . '&destinations=' . str_replace(',', '.', (string) $point->lat) . ',' . str_replace(',', '.', (string) $point->lng) . '&key=' . $google_api_key;
			$result = FileSystem::read($url);
			$result = Json::decode($result);
			if ($result->status === 'OK' && $result->rows[0]->elements[0]->status === 'OK') {
				return (float) $result->rows[0]->elements[0]->distance->value;
			}
		} catch (JsonException) {
		}

		/**
		 * @author Jakub Vrána
		 * @link   http://php.vrana.cz/vzdalenost-dvou-zemepisnych-bodu.php
		 */
		return
			acos(cos(deg2rad($this->lat)) * cos(deg2rad($this->lng)) * cos(deg2rad($point->lat)) * cos(deg2rad($point->lng)) + cos(deg2rad($this->lat)) * sin(deg2rad($this->lng)) * cos(deg2rad($point->lat)) * sin(deg2rad($point->lng)) + sin(deg2rad($this->lat)) * sin(deg2rad($point->lat)))
			* 6372.795 // Great circle radius
			* 1000; // km to m
	}


	/**
	 * @deprecated Use distanceTo() method.
	 */
	public function getDistanceTo(self $point, string $google_api_key = null): float
	{
		return $this->distanceTo(...func_get_args());
	}


	/**
	 * @deprecated Use $object->lat.
	 */
	public function getLat(): float
	{
		return $this->lat;
	}


	/**
	 * @deprecated Use $object->lng.
	 */
	public function getLng(): float
	{
		return $this->lng;
	}


	/**
	 * @deprecated Use $object->address.
	 */
	public function getAddress(): ?string
	{
		return $this->address;
	}
}
