<?php
/**
 * World Airport Codes Importer.
 *
 * @copyright Copyright (c) 2013 Airporting.me (http://airporting.me)
 */

namespace Airportingme\WorldAirportCodesImporter;

use Doctrine\Common\Cache\FilesystemCache;
use Guzzle\Cache\DoctrineCacheAdapter;
use Scraping\Http\Client;
use Scraping\DomCrawler\Crawler;

/**
 * World Airport Codes Importer.
 *
 * Imports World Aiport Codes airport data in JSON format.
 * Highly inspired by {@link https://github.com/lhaig/airportdb airportdb project}.
 *
 * @copyright Copyright (c) 2013 Airporting.me (http://airporting.me)
 * @author    LF Bittencourt <lf@lfbittencourt.com>
 */
class Importer
{
    /**
     * Airport data keys.
     *
     * @var array
     */
    protected static $airportKeys = array(
        'airportCode',
        'airportName',
        'runwayLength',
        'runwayElevation',
        'city',
        'country',
        'countryAbbr',
        'airportGuide',
        'longitude',
        'latitude',
        'worldAreaCode',
        'gmtOffset',
        'telephone',
        'fax',
        'email',
        'url',
    );

    /**
     * HTTP client.
     *
     * @var Client
     */
    protected $client;

    /**
     * If TRUE, displays details about the import process.
     *
     * @var boolean
     */
    protected $verbose = true;

    /**
     * Import results.
     *
     * @var array
     */
    protected $results = array();

    /**
     * Public constructor.
     *
     * @param boolean $verbose If TRUE, displays details about the import process.
     */
    public function __construct($verbose = true)
    {
        $baseUrl = 'http://www.world-airport-codes.com';
        $backoffRegex = '/Can\'t connect to local MySQL server/';

        $this->client = new Client($baseUrl, $backoffRegex);
        $this->verbose = $verbose;
    }

    /**
     * Clears airport data.
     *
     * @param  string $data
     * @return string|null
     */
    public static function clearData($data)
    {
        $data = preg_replace('/\r?\n/', ' ', $data);
        $data = trim(self::decode($data));
        $data = preg_replace('/^:\s+/', '', $data);
        $data = preg_replace('/ \(\?\)$/', '', $data);
        $data = preg_replace('/ ft\.$/', '', $data);
        $data = preg_replace('/^Unavailable$/', '', $data);
        $data = preg_replace('/^Unknown \(add\)$/', '', $data);

        // World Airport Codes uses an antispam trick to display emails.
        if (strpos($data, 'string1') !== false) {
            $pattern = "/^.*string1 = \"([^\"]*)\".*string3 = \"([^\"]*)\".*$/";
            $data = preg_replace($pattern, '$1@$2', $data);

            // Clears incorrectly filled emails.
            if (filter_var($data, FILTER_VALIDATE_EMAIL) === false) {
                $data = '';
            }
        }

        return $data === '' ? null : $data;
    }

    /**
     * Converts latitude/longitude DMSs to decimal format.
     *
     * @param  string $dms
     * @return float|null Returns null if DMS is invalid.
     * @todo   Move to external library.
     */
    public function convertDms($dms)
    {
        $matches = array();

        if (preg_match_all('/\d+/', $dms, $matches) === 3) {
            list($degrees, $minutes, $seconds) = array_pop($matches);

            $result = $degrees + ($minutes / 60) + ($seconds / 3600);

            if (preg_match('/[SW]$/', $dms) === 1) {
                $result *= -1;
            }

            return $result;
        }

        return null;
    }

    /**
     * Convert all HTML entities to their applicable characters.
     *
     * Useful to sanitize URLs and airport data from World Airport Codes.
     *
     * @param  string $string
     * @return string
     */
    protected static function decode($string)
    {
        return html_entity_decode($string, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Gets airport data in JSON format.
     *
     * @return string
     */
    public function getJson()
    {
        $this->import();

        return json_encode($this->results);
    }

    /**
     * Imports airport data.
     */
    protected function import()
    {
        $startTime = microtime(true);

        $this->log('Starting import...');

        $requests = array();

        foreach (range('a', 'z') as $letter) {
            $uri = sprintf('/alphabetical/airport-code/%s.html', $letter);
            $callback = array($this, 'processIndexPage');

            $requests[] = $this->client->get($uri, $callback);
        }

        $this->client->send($requests);

        $minutes = (microtime(true) - $startTime) / 60;

        $this->log(
            'Import fisinhed. %d airports imported in %01.2f minutes.',
            count($this->results),
            $minutes
        );
    }

    /**
     * Displays a message.
     *
     * @param string|mixed $message Simple string message
     *                              or sprintf's format param.
     */
    protected function log($message)
    {
        if ($this->verbose) {
            if (func_num_args() > 1) {
                $message = call_user_func_array('sprintf', func_get_args());
            }

            echo $message, PHP_EOL;
        }
    }

    /**
     * Processes an airport page.
     *
     * Its visibility is public to allow usage as callback.
     *
     * @see    Importer::processIndexPage()
     * @param  Crawler $crawler
     */
    public function processAirportPage(Crawler $crawler)
    {
        $this->log('Processing %s...', $crawler->getResponse()->getEffectiveUrl());

        $crawlers = $crawler->filter('.airportdetails span.detail');
        $values = $crawlers->each(
            function (Crawler $crawler) {
                // Is an airport URL?
                if (($data = $crawler->text()) === ': Visit Website (?)') {
                    $data = $crawler->filter('a')->attr('href');
                }

                return Importer::clearData($data);
            }
        );

        $result = array_combine(self::$airportKeys, $values);

        // Extra treatments.

        if ($result['gmtOffset'] !== null) {
            $result['gmtOffset'] = (int) $result['gmtOffset'];
        }

        $result['latitude'] = $this->convertDms($result['latitude']);
        $result['longitude'] = $this->convertDms($result['longitude']);

        if ($result['runwayElevation'] !== null) {
            $result['runwayElevation'] = (float) $result['runwayElevation'];
        }

        if ($result['runwayLength'] !== null) {
            $result['runwayLength'] = (float) $result['runwayLength'];
        }

        if ($result['worldAreaCode'] !== null) {
            $result['worldAreaCode'] = (int) $result['worldAreaCode'];
        }

        $this->results[] = $result;

        $this->log('New airport: %s (%s).', $result['airportName'], $result['airportCode']);
    }

    /**
     * Processes an index page.
     *
     * Its visibility is public to allow usage as callback.
     *
     * @see    Importer::import()
     * @param  Crawler $crawler
     */
    public function processIndexPage(Crawler $crawler)
    {
        $this->log('Processing %s...', $crawler->getResponse()->getEffectiveUrl());

        $infoImages = $crawler->filter('img[src="/images/info.gif"]');
        $requests = array();

        foreach ($infoImages as $infoImage) {
            $href = $infoImage->parentNode->getAttribute('href');
            $uri = self::decode($href);
            $callback = array($this, 'processAirportPage');

            $requests[] = $this->client->get($uri, $callback);
        }

        $this->client->send($requests);
    }
}
