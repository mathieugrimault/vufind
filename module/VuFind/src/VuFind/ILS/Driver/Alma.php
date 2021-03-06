<?php
/**
 * Alma ILS Driver
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2017.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  ILS_Drivers
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:ils_drivers Wiki
 */
namespace VuFind\ILS\Driver;

use SimpleXMLElement;
use VuFind\Exception\ILS as ILSException;
use Zend\Http\Headers;

/**
 * Alma ILS Driver
 *
 * @category VuFind
 * @package  ILS_Drivers
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:ils_drivers Wiki
 */
class Alma extends AbstractBase implements \VuFindHttp\HttpServiceAwareInterface,
    \Zend\Log\LoggerAwareInterface
{
    use \VuFindHttp\HttpServiceAwareTrait;
    use \VuFind\Log\LoggerAwareTrait;
    use CacheTrait;

    /**
     * Alma API base URL.
     *
     * @var string
     */
    protected $baseUrl;

    /**
     * Alma API key.
     *
     * @var string
     */
    protected $apiKey;

    /**
     * Date converter
     *
     * @var \VuFind\Date\Converter
     */
    protected $dateConverter;

    /**
     * Configuration loader
     *
     * @var \VuFind\Config\PluginManager
     */
    protected $configLoader;

    /**
     * Constructor
     *
     * @param \VuFind\Date\Converter       $dateConverter Date converter object
     * @param \VuFind\Config\PluginManager $configLoader  Plugin manager
     */
    public function __construct(
        \VuFind\Date\Converter $dateConverter,
        \VuFind\Config\PluginManager $configLoader
    ) {
        $this->dateConverter = $dateConverter;
        $this->configLoader = $configLoader;
    }

    /**
     * Initialize the driver.
     *
     * Validate configuration and perform all resource-intensive tasks needed to
     * make the driver active.
     *
     * @throws ILSException
     * @return void
     */
    public function init()
    {
        if (empty($this->config)) {
            throw new ILSException('Configuration needs to be set.');
        }
        $this->baseUrl = $this->config['Catalog']['apiBaseUrl'];
        $this->apiKey = $this->config['Catalog']['apiKey'];
    }

    /**
     * Make an HTTP request against Alma
     *
     * @param string        $path          Path to retrieve from API (excluding base
     *                                     URL/API key)
     * @param array         $paramsGet     Additional GET params
     * @param array         $paramsPost    Additional POST params
     * @param string        $method        GET or POST. Default is GET.
     * @param string        $rawBody       Request body.
     * @param Headers|array $headers       Add headers to the call.
     * @param array         $allowedErrors HTTP status codes that are not treated as
     *                                     API errors.
     * @param bool          $returnStatus  Whether to return HTTP status in addition
     *                                     to the response.
     *
     * @throws ILSException
     * @return NULL|SimpleXMLElement
     */
    protected function makeRequest(
        $path,
        $paramsGet = [],
        $paramsPost = [],
        $method = 'GET',
        $rawBody = null,
        $headers = null,
        $allowedErrors = [],
        $returnStatus = false
    ) {
        // Set some variables
        $result = null;
        $statusCode = null;
        $returnValue = null;
        $startTime = microtime(true);

        try {
            // Set API key if it is not already available in the GET params
            if (!isset($paramsGet['apikey'])) {
                $paramsGet['apikey'] = $this->apiKey;
            }

            // Create the API URL
            $url = strpos($path, '://') === false ? $this->baseUrl . $path : $path;

            // Create client with API URL
            $client = $this->httpService->createClient($url);

            // Set method
            $client->setMethod($method);

            // Set timeout
            $timeout = $this->config['Catalog']['http_timeout'] ?? 30;
            $client->setOptions(['timeout' => $timeout]);

            // Set other GET parameters (apikey and other URL parameters are used
            // also with e.g. POST requests)
            $client->setParameterGet($paramsGet);
            // Set POST parameters
            if ($method == 'POST') {
                $client->setParameterPost($paramsPost);
            }

            // Set body if applicable
            if (isset($rawBody)) {
                $client->setRawBody($rawBody);
            }

            // Set headers if applicable
            if (isset($headers)) {
                $client->setHeaders($headers);
            }

            // Execute HTTP call
            $result = $client->send();
        } catch (\Exception $e) {
            $this->logError("$method request for $url failed: " . $e->getMessage());
            throw new ILSException($e->getMessage());
        }

        $duration = round(microtime(true) - $startTime, 4);
        $urlParams = $client->getRequest()->getQuery()->toString();
        $code = $result->getStatusCode();
        $this->debug(
            "[$duration] $method request for $url?$urlParams results ($code):\n"
            . $result->getBody()
        );

        // Get the HTTP status code
        $statusCode = $result->getStatusCode();

        // Check for error
        if ($result->isServerError()) {
            $this->logError(
                "$method request for $url failed, HTTP error code: $statusCode"
            );
            throw new ILSException('HTTP error code: ' . $statusCode, $statusCode);
        }

        $answer = $result->getBody();
        $answer = str_replace('xmlns=', 'ns=', $answer);
        try {
            $xml = simplexml_load_string($answer);
        } catch (\Exception $e) {
            $this->logError(
                "Could not parse response for $method request for $url: "
                . $e->getMessage() . ". Response was:\n"
                . $result->getHeaders()->toString()
                . "\n\n$answer"
            );
            throw new ILSException($e->getMessage());
        }
        if ($result->isSuccess() || in_array($statusCode, $allowedErrors)) {
            if (!$xml && $result->isServerError()) {
                $error = 'XML is not valid or HTTP error, URL: ' . $url .
                    ', HTTP status code: ' . $statusCode;
                $this->logError($error);
                throw new ILSException($error, $statusCode);
            }
            $returnValue = $xml;
        } else {
            $almaErrorMsg = $xml->errorList->error[0]->errorMessage;
            error_log(
                '[ALMA] ' . $almaErrorMsg . ' | Call to: ' . $client->getUri() .
                '. GET params: ' . var_export($paramsGet, true) . '. POST params: ' .
                var_export($paramsPost, true) . '. Result body: ' .
                $result->getBody() . '. HTTP status code: ' . $statusCode
            );
            throw new ILSException(
                "Alma error message for $method request for $url: "
                . $almaErrorMsg . ' | HTTP error code: '
                . $statusCode,
                $statusCode
            );
        }

        return $returnStatus ? [$returnValue, $statusCode] : $returnValue;
    }

    /**
     * Given an item, return the availability status.
     *
     * @param \SimpleXMLElement $item Item data
     *
     * @return bool
     */
    protected function getAvailabilityFromItem($item)
    {
        return (string)$item->item_data->base_status === '1';
    }

    /**
     * Get Holding
     *
     * This is responsible for retrieving the holding information of a certain
     * record.
     *
     * @param string $id      The record id to retrieve the holdings for
     * @param array  $patron  Patron data
     * @param array  $options Additional options
     *
     * @return array On success an array with the key "total" containing the total
     * number of items for the given bib id, and the key "holdings" containing an
     * array of holding information each one with these keys: id, source,
     * availability, status, location, reserve, callnumber, duedate, returnDate,
     * number, barcode, item_notes, item_id, holding_id, addLink, description
     */
    public function getHolding($id, $patron = null, array $options = [])
    {
        // Prepare result array with default values. If no API result can be received
        // these will be returned.
        $results['total'] = 0;
        $results['holdings'] = [];

        // Correct copy count in case of paging
        $copyCount = $options['offset'] ?? 0;
        $patronId = $patron['id'] ?? null;

        // Paging parameters for paginated API call. The "limit" tells the API how
        // many items the call should return at once (e. g. 10). The "offset" defines
        // the range (e. g. get items 30 to 40). With these parameters we are able to
        // use a paginator for paging through many items.
        $apiPagingParams = '';
        if ($options['itemLimit'] ?? null) {
            $apiPagingParams = 'limit=' . urlencode($options['itemLimit'])
                . '&offset=' . urlencode($options['offset'] ?? 0);
        }

        // The path for the API call. We call "ALL" available items, but not at once
        // as a pagination mechanism is used. If paging params are not set for some
        // reason, the first 10 items are called which is the default API behaviour.
        $itemsPath = '/bibs/' . urlencode($id) . '/holdings/ALL/items?'
            . $apiPagingParams
            . '&order_by=library,location,enum_a,enum_b&direction=desc';

        if ($items = $this->makeRequest($itemsPath)) {
            // Get the total number of items returned from the API call and set it to
            // a class variable. It is then used in VuFind\RecordTab\HoldingsILS for
            // the items paginator.
            $results['total'] = (int)$items->attributes()->total_record_count;

            foreach ($items->item as $item) {
                $number = ++$copyCount;
                $holdingId = (string)$item->holding_data->holding_id;
                $itemId = (string)$item->item_data->pid;
                $processType = (string)$item->item_data->process_type;
                $barcode = (string)$item->item_data->barcode;
                $requested = ((string)$item->item_data->requested == 'false')
                             ? false
                             : true;

                // For some data we need to do additional API calls due to the Alma
                // API architecture.
                $duedate = ($requested) ? 'requested' : null;
                if ($processType === 'LOAN' && !$requested) {
                    $loanDataPath = '/bibs/' . urlencode($id) . '/holdings/'
                        . urlencode($holdingId) . '/items/'
                        . urlencode($itemId) . '/loans';
                    $loanData = $this->makeRequest($loanDataPath);
                    $loan = $loanData->item_loan;
                    $duedate = $this->parseDate((string)$loan->due_date);
                }

                // Calculate request options if a user is logged-in
                if ($patronId) {
                    // Call the request-options API for the logged-in user
                    $requestOptionsPath = '/bibs/' . urlencode($id)
                       . '/holdings/' . urlencode($holdingId) . '/items/'
                       . urlencode($itemId) . '/request-options?user_id='
                       . urlencode($patronId);

                    // Make the API request
                    $requestOptions = $this->makeRequest($requestOptionsPath);

                    // Get all possible request types from the API answer
                    $requestTypes = $requestOptions->xpath(
                        '/request_options/request_option//type'
                    );

                    // Add all allowed request types to an array
                    $requestTypesArr = [];
                    foreach ($requestTypes as $requestType) {
                        $requestTypesArr[] = (string)$requestType;
                    }

                    // If HOLD is an allowed request type, add the link for placing
                    // a hold
                    $addLink = in_array('HOLD', $requestTypesArr);
                }

                if ($item->item_data->public_note != null
                    && !empty($item->item_data->public_note)
                ) {
                    $itemNotes = [(string)$item->item_data->public_note];
                }

                if ($item->item_data->description != null
                    && !empty($item->item_data->description)
                ) {
                    $number = (string)$item->item_data->description;
                    $description = (string)$item->item_data->description;
                }

                $results['holdings'][] = [
                    'id' => $id,
                    'source' => 'Solr',
                    'availability' => $this->getAvailabilityFromItem($item),
                    'status' => (string)$item->item_data->base_status[0]
                        ->attributes()['desc'],
                    'location' => (string)$item->item_data->location,
                    'reserve' => 'N',   // TODO: support reserve status
                    'callnumber' => (string)$item->holding_data->call_number,
                    'duedate' => $duedate,
                    'returnDate' => false, // TODO: support recent returns
                    'number' => $number,
                    'barcode' => empty($barcode) ? 'n/a' : $barcode,
                    'item_notes' => $itemNotes ?? null,
                    'item_id' => $itemId,
                    'holding_id' => $holdingId,
                    'addLink' => $addLink ?? false,
                    // For Alma title-level hold requests
                    'description' => $description ?? null
                ];
            }
        }

        // Fetch also digital and/or electronic inventory if configured
        $types = $this->getInventoryTypes();
        if (in_array('d_avail', $types) || in_array('e_avail', $types)) {
            // No need for physical items
            $key = array_search('p_avail', $types);
            if (false !== $key) {
                unset($types[$key]);
            }
            $statuses = $this->getStatusesForInventoryTypes((array)$id, $types);
            $electronic = [];
            foreach ($statuses as $record) {
                foreach ($record as $status) {
                    $electronic[] = $status;
                }
            }
            $results['electronic_holdings'] = $electronic;
        }

        return $results;
    }

    /**
     * Check for request blocks.
     *
     * @param array $patron The patron array with username and password
     *
     * @return array|boolean    An array of block messages or false if there are no
     *                          blocks
     * @author Michael Birkner
     */
    public function getRequestBlocks($patron)
    {
        return $this->getAccountBlocks($patron);
    }

    /**
     * Check for account blocks in Alma and cache them.
     *
     * @param array $patron The patron array with username and password
     *
     * @return array|boolean    An array of block messages or false if there are no
     *                          blocks
     * @author Michael Birkner
     */
    public function getAccountBlocks($patron)
    {
        $patronId = $patron['id'];
        $cacheId = 'alma|user|' . $patronId . '|blocks';
        $cachedBlocks = $this->getCachedData($cacheId);
        if ($cachedBlocks !== null) {
            return $cachedBlocks;
        }

        $xml = $this->makeRequest('/users/' . $patronId);
        if ($xml == null || empty($xml)) {
            return false;
        }

        $userBlocks = $xml->user_blocks->user_block;
        if ($userBlocks == null || empty($userBlocks)) {
            return false;
        }

        $blocks = [];
        foreach ($userBlocks as $block) {
            $blockStatus = (string)$block->block_status;
            if ($blockStatus === 'ACTIVE') {
                $blockNote = (isset($block->block_note))
                             ? (string)$block->block_note
                             : null;
                $blockDesc = (string)$block->block_description->attributes()->desc;
                $blockDesc = ($blockNote != null)
                             ? $blockDesc . '. ' . $blockNote
                             : $blockDesc;
                $blocks[] = $blockDesc;
            }
        }

        if (!empty($blocks)) {
            $this->putCachedData($cacheId, $blocks);
            return $blocks;
        } else {
            $this->putCachedData($cacheId, false);
            return false;
        }
    }

    /**
     * Get an Alma fulfillment unit by an Alma location.
     *
     * @param string $locationCode     A location code, e. g. "SCI"
     * @param array  $fulfillmentUnits An array of fulfillment units with all its
     *                                 locations.
     *
     * @return string|NULL              Null if the location was not found or a
     *                                  string specifying the fulfillment unit of
     *                                  the location that was found.
     * @author Michael Birkner
     */
    protected function getFulfillmentUnitByLocation($locationCode, $fulfillmentUnits)
    {
        foreach ($fulfillmentUnits as $key => $val) {
            if (array_search($locationCode, $val) !== false) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Create a user in Alma via API call
     *
     * @param array $formParams The data from the "create new account" form
     *
     * @throws \VuFind\Exception\Auth
     *
     * @return NULL|SimpleXMLElement
     * @author Michael Birkner
     */
    public function createAlmaUser($formParams)
    {
        // Get config for creating new Alma users from Alma.ini
        $newUserConfig = $this->config['NewUser'];

        // Check if config params are all set
        $configParams = [
            'recordType', 'userGroup', 'preferredLanguage',
            'accountType', 'status', 'emailType', 'idType'
        ];
        foreach ($configParams as $configParam) {
            if (!isset($newUserConfig[$configParam])
                || empty(trim($newUserConfig[$configParam]))
            ) {
                $errorMessage = 'Configuration "' . $configParam . '" is not set ' .
                                'in Alma.ini in the [NewUser] section!';
                error_log('[ALMA]: ' . $errorMessage);
                throw new \VuFind\Exception\Auth($errorMessage);
            }
        }

        // Calculate expiry date based on config in Alma.ini
        $dateNow = new \DateTime('now');
        $expiryDate = null;
        if (isset($newUserConfig['expiryDate'])
            && !empty(trim($newUserConfig['expiryDate']))
        ) {
            try {
                $expiryDate = $dateNow->add(
                    new \DateInterval($newUserConfig['expiryDate'])
                );
            } catch (\Exception $exception) {
                $errorMessage = 'Configuration "expiryDate" in Alma.ini (see ' .
                                '[NewUser] section) has the wrong format!';
                error_log('[ALMA]: ' . $errorMessage);
                throw new \VuFind\Exception\Auth($errorMessage);
            }
        } else {
            $expiryDate = $dateNow->add(new \DateInterval('P1Y'));
        }
        $expiryDateXml = ($expiryDate != null)
                 ? '<expiry_date>' . $expiryDate->format('Y-m-d') . 'Z</expiry_date>'
                 : '';

        // Calculate purge date based on config in Alma.ini
        $purgeDate = null;
        if (isset($newUserConfig['purgeDate'])
            && !empty(trim($newUserConfig['purgeDate']))
        ) {
            try {
                $purgeDate = $dateNow->add(
                    new \DateInterval($newUserConfig['purgeDate'])
                );
            } catch (\Exception $exception) {
                $errorMessage = 'Configuration "purgeDate" in Alma.ini (see ' .
                                '[NewUser] section) has the wrong format!';
                error_log('[ALMA]: ' . $errorMessage);
                throw new \VuFind\Exception\Auth($errorMessage);
            }
        }
        $purgeDateXml = ($purgeDate != null)
                    ? '<purge_date>' . $purgeDate->format('Y-m-d') . 'Z</purge_date>'
                    : '';

        // Create user XML for Alma API
        $userXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<user>'
        . '<record_type>' . $this->config['NewUser']['recordType'] . '</record_type>'
        . '<first_name>' . $formParams['firstname'] . '</first_name>'
        . '<last_name>' . $formParams['lastname'] . '</last_name>'
        . '<user_group>' . $this->config['NewUser']['userGroup'] . '</user_group>'
        . '<preferred_language>' . $this->config['NewUser']['preferredLanguage'] .
          '</preferred_language>'
        . $expiryDateXml
        . $purgeDateXml
        . '<account_type>' . $this->config['NewUser']['accountType'] .
          '</account_type>'
        . '<status>' . $this->config['NewUser']['status'] . '</status>'
        . '<contact_info>'
        . '<emails>'
        . '<email preferred="true">'
        . '<email_address>' . $formParams['email'] . '</email_address>'
        . '<email_types>'
        . '<email_type>' . $this->config['NewUser']['emailType'] . '</email_type>'
        . '</email_types>'
        . '</email>'
        . '</emails>'
        . '</contact_info>'
        . '<user_identifiers>'
        . '<user_identifier>'
        . '<id_type>' . $this->config['NewUser']['idType'] . '</id_type>'
        . '<value>' . $formParams['username'] . '</value>'
        . '</user_identifier>'
        . '</user_identifiers>'
        . '</user>';

        // Remove whitespaces from XML
        $userXml = preg_replace("/\n/i", "", $userXml);
        $userXml = preg_replace("/>\s*</i", "><", $userXml);

        // Create user in Alma
        $almaAnswer = $this->makeRequest(
            '/users',
            [],
            [],
            'POST',
            $userXml,
            ['Content-Type' => 'application/xml']
        );

        // Return the XML from Alma on success. On error, an exception is thrown
        // in makeRequest
        return $almaAnswer;
    }

    /**
     * Patron Login
     *
     * This is responsible for authenticating a patron against the catalog.
     *
     * @param string $barcode  The patrons barcode.
     * @param string $password The patrons password.
     *
     * @return string[]|NULL
     */
    public function patronLogin($barcode, $password)
    {
        $loginMethod = $this->config['Catalog']['loginMethod'] ?? 'vufind';
        if ('password' === $loginMethod) {
            // Create parameters for API call
            $getParams = [
                'user_id_type' => 'all_unique',
                'op' => 'auth',
                'password' => $password
            ];

            // Try to authenticate the user with Alma
            list($response, $status) = $this->makeRequest(
                '/users/' . urlencode($barcode),
                $getParams,
                [],
                'POST',
                null,
                null,
                [400],
                true
            );
            if (400 === $status) {
                return null;
            }
        }

        if ('password' === $loginMethod || 'vufind' === $loginMethod) {
            // Create parameters for API call
            $getParams = [
                'user_id_type' => 'all_unique',
                'view' => 'brief',
                'expand' => 'none'
            ];

            // Check for patron in Alma
            $response = $this->makeRequest(
                '/users/' . urlencode($barcode),
                $getParams
            );

            if ($response !== null) {
                return [
                    'id' => (string)$response->primary_id,
                    'cat_username' => trim($barcode),
                    'cat_password' => trim($password)
                ];
            }
        }

        return null;
    }

    /**
     * Get Patron Profile
     *
     * This is responsible for retrieving the profile for a specific patron.
     *
     * @param array $patron The patron array
     *
     * @return array Array of the patron's profile data on success.
     */
    public function getMyProfile($patron)
    {
        $patronId = $patron['id'];
        $xml = $this->makeRequest('/users/' . $patronId);
        if (empty($xml)) {
            return [];
        }
        $profile = [
            'firstname'  => (isset($xml->first_name))
                                ? (string)$xml->first_name
                                : null,
            'lastname'   => (isset($xml->last_name))
                                ? (string)$xml->last_name
                                : null,
            'group'      => (isset($xml->user_group['desc']))
                                ? (string)$xml->user_group['desc']
                                : null,
            'group_code' => (isset($xml->user_group))
                                ? (string)$xml->user_group
                                : null
        ];
        $contact = $xml->contact_info;
        if ($contact) {
            if ($contact->addresses) {
                $address = $contact->addresses[0]->address;
                $profile['address1'] =  (isset($address->line1))
                                            ? (string)$address->line1
                                            : null;
                $profile['address2'] =  (isset($address->line2))
                                            ? (string)$address->line2
                                            : null;
                $profile['address3'] =  (isset($address->line3))
                                            ? (string)$address->line3
                                            : null;
                $profile['zip']      =  (isset($address->postal_code))
                                            ? (string)$address->postal_code
                                            : null;
                $profile['city']     =  (isset($address->city))
                                            ? (string)$address->city
                                            : null;
                $profile['country']  =  (isset($address->country))
                                            ? (string)$address->country
                                            : null;
            }
            if ($contact->phones) {
                $profile['phone'] = (isset($contact->phones[0]->phone->phone_number))
                                   ? (string)$contact->phones[0]->phone->phone_number
                                   : null;
            }
        }

        // Cache the user group code
        $cacheId = 'alma|user|' . $patronId . '|group_code';
        $this->putCachedData($cacheId, $profile['group_code'] ?? null);

        return $profile;
    }

    /**
     * Get Patron Fines
     *
     * This is responsible for retrieving all fines by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @return mixed        Array of the patron's fines on success.
     */
    public function getMyFines($patron)
    {
        $xml = $this->makeRequest(
            '/users/' . $patron['id'] . '/fees'
        );
        $fineList = [];
        foreach ($xml as $fee) {
            $created = (string)$fee->creation_time;
            $checkout = (string)$fee->status_time;
            $fineList[] = [
                "title"   => (string)($fee->title ?? ''),
                "amount"   => round(floatval($fee->original_amount) * 100),
                "balance"  => round(floatval($fee->balance) * 100),
                "createdate" => $this->dateConverter->convertToDisplayDateAndTime(
                    'Y-m-d\TH:i:s.???T',
                    $created
                ),
                "checkout" => $this->dateConverter->convertToDisplayDateAndTime(
                    'Y-m-d\TH:i:s.???T',
                    $checkout
                ),
                "fine"     => (string)$fee->type['desc']
            ];
        }
        return $fineList;
    }

    /**
     * Get Patron Holds
     *
     * This is responsible for retrieving all holds by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @return mixed        Array of the patron's holds on success.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getMyHolds($patron)
    {
        $xml = $this->makeRequest(
            '/users/' . $patron['id'] . '/requests',
            ['request_type' => 'HOLD']
        );
        $holdList = [];
        foreach ($xml as $request) {
            $holdList[] = [
                'create' => (string)$request->request_date,
                'expire' => (string)$request->last_interest_date,
                'id' => (string)$request->request_id,
                'in_transit' => (string)$request->request_status !== 'On Hold Shelf',
                'item_id' => (string)$request->mms_id,
                'location' => (string)$request->pickup_location,
                'processed' => $request->item_policy === 'InterlibraryLoan'
                    && (string)$request->request_status !== 'Not Started',
                'title' => (string)$request->title,
                /*
                // VuFind keys
                'available'         => $request->,
                'canceled'          => $request->,
                'institution_dbkey' => $request->,
                'institution_id'    => $request->,
                'institution_name'  => $request->,
                'position'          => $request->,
                'reqnum'            => $request->,
                'requestGroup'      => $request->,
                'source'            => $request->,
                // Alma keys
                "author": null,
                "comment": null,
                "desc": "Book"
                "description": null,
                "material_type": {
                "pickup_location": "Burns",
                "pickup_location_library": "BURNS",
                "pickup_location_type": "LIBRARY",
                "place_in_queue": 1,
                "request_date": "2013-11-12Z"
                "request_id": "83013520000121",
                "request_status": "NOT_STARTED",
                "request_type": "HOLD",
                "title": "Test title",
                "value": "BK",
                */
            ];
        }
        return $holdList;
    }

    /**
     * Cancel hold requests.
     *
     * @param array $cancelDetails An associative array with two keys: patron
     *                             (array returned by the driver's
     *                             patronLogin method) and details (an array
     *                             of strings eturned by the driver's
     *                             getCancelHoldDetails method)
     *
     * @return array                Associative array containing with keys 'count'
     *                                 (number of items successfully cancelled) and
     *                                 'items' (array of successful cancellations).
     */
    public function cancelHolds($cancelDetails)
    {
        $returnArray = [];
        $patronId = $cancelDetails['patron']['id'];
        $count = 0;

        foreach ($cancelDetails['details'] as $requestId) {
            $item = [];
            try {
                // Get some details of the requested items as we need them below.
                // We only can get them from an API request.
                $requestDetails = $this->makeRequest(
                    $this->baseUrl .
                        '/users/' . urlencode($patronId) .
                        '/requests/' . urlencode($requestId)
                );

                $mmsId = (isset($requestDetails->mms_id))
                          ? (string)$requestDetails->mms_id
                          : (string)$requestDetails->mms_id;

                // Delete the request in Alma
                $apiResult = $this->makeRequest(
                    $this->baseUrl .
                    '/users/' . urlencode($patronId) .
                    '/requests/' . urlencode($requestId),
                    ['reason' => 'CancelledAtPatronRequest'],
                    [],
                    'DELETE'
                );

                // Adding to "count" variable and setting values to return array
                $count++;
                $item[$mmsId]['success'] = true;
                $item[$mmsId]['status'] = 'hold_cancel_success';
            } catch (ILSException $e) {
                if (isset($apiResult['xml'])) {
                    $almaErrorCode = $apiResult['xml']->errorList->error->errorCode;
                    $sysMessage = $apiResult['xml']->errorList->error->errorMessage;
                } else {
                    $almaErrorCode = 'No error code available';
                    $sysMessage = 'HTTP status code: ' .
                         ($e->getCode() ?? 'Code not available');
                }
                $item[$mmsId]['success'] = false;
                $item[$mmsId]['status'] = 'hold_cancel_fail';
                $item[$mmsId]['sysMessage'] = $sysMessage . '. ' .
                         'Alma MMS ID: ' . $mmsId . '. ' .
                         'Alma request ID: ' . $requestId . '. ' .
                         'Alma error code: ' . $almaErrorCode;
            }

            $returnArray['items'] = $item;
        }

        $returnArray['count'] = $count;

        return $returnArray;
    }

    /**
     * Get details of a single hold request.
     *
     * @param array $holdDetails One of the item arrays returned by the
     *                           getMyHolds method
     *
     * @return string            The Alma request ID
     */
    public function getCancelHoldDetails($holdDetails)
    {
        return $holdDetails['id'];
    }

    /**
     * Get Patron Storage Retrieval Requests
     *
     * This is responsible for retrieving all call slips by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @return mixed        Array of the patron's holds
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getMyStorageRetrievalRequests($patron)
    {
        $xml = $this->makeRequest(
            '/users/' . $patron['id'] . '/requests',
            ['request_type' => 'MOVE']
        );
        $holdList = [];
        for ($i = 0; $i < count($xml->user_requests); $i++) {
            $request = $xml->user_requests[$i];
            if (!isset($request->item_policy)
                || $request->item_policy !== 'Archive'
            ) {
                continue;
            }
            $holdList[] = [
                'create' => $request->request_date,
                'expire' => $request->last_interest_date,
                'id' => $request->request_id,
                'in_transit' => $request->request_status !== 'IN_PROCESS',
                'item_id' => $request->mms_id,
                'location' => $request->pickup_location,
                'processed' => $request->item_policy === 'InterlibraryLoan'
                    && $request->request_status !== 'NOT_STARTED',
                'title' => $request->title,
            ];
        }
        return $holdList;
    }

    /**
     * Get Patron ILL Requests
     *
     * This is responsible for retrieving all ILL requests by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @return mixed        Array of the patron's ILL requests
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getMyILLRequests($patron)
    {
        $xml = $this->makeRequest(
            '/users/' . $patron['id'] . '/requests',
            ['request_type' => 'MOVE']
        );
        $holdList = [];
        for ($i = 0; $i < count($xml->user_requests); $i++) {
            $request = $xml->user_requests[$i];
            if (!isset($request->item_policy)
                || $request->item_policy !== 'InterlibraryLoan'
            ) {
                continue;
            }
            $holdList[] = [
                'create' => $request->request_date,
                'expire' => $request->last_interest_date,
                'id' => $request->request_id,
                'in_transit' => $request->request_status !== 'IN_PROCESS',
                'item_id' => $request->mms_id,
                'location' => $request->pickup_location,
                'processed' => $request->item_policy === 'InterlibraryLoan'
                    && $request->request_status !== 'NOT_STARTED',
                'title' => $request->title,
            ];
        }
        return $holdList;
    }

    /**
     * Get transactions of the current patron.
     *
     * @param array $patron The patron array from patronLogin
     * @param array $params Parameters
     *
     * @return array Transaction information as array.
     *
     * @author Michael Birkner
     */
    public function getMyTransactions($patron, $params = [])
    {
        // Defining the return value
        $returnArray = [];

        // Get the patron id
        $patronId = $patron['id'];

        // Create a timestamp for calculating the due / overdue status
        $nowTS = time();

        $sort = explode(
            ' ', !empty($params['sort']) ? $params['sort'] : 'checkout desc', 2
        );
        if ($sort[0] == 'checkout') {
            $sortKey = 'loan_date';
        } elseif ($sort[0] == 'title') {
            $sortKey = 'title';
        } else {
            $sortKey = 'due_date';
        }
        $direction = (isset($sort[1]) && 'desc' === $sort[1]) ? 'DESC' : 'ASC';

        $pageSize = $params['limit'] ?? 50;
        $params = [
            'limit' => $pageSize,
            'offset' => isset($params['page'])
                ? ($params['page'] - 1) * $pageSize : 0,
            'order_by' => $sortKey,
            'direction' => $direction,
            'expand' => 'renewable'
        ];

        // Get user loans from Alma API
        $apiResult = $this->makeRequest(
            '/users/' . $patronId . '/loans',
            $params
        );

        // If there is an API result, process it
        $totalCount = 0;
        if ($apiResult) {
            $totalCount = $apiResult->attributes()->total_record_count;
            // Iterate over all item loans
            foreach ($apiResult->item_loan as $itemLoan) {
                $loan['duedate'] = $this->parseDate(
                    (string)$itemLoan->due_date,
                    true
                );
                //$loan['dueTime'] = ;
                $loan['dueStatus'] = null; // Calculated below
                $loan['id'] = (string)$itemLoan->mms_id;
                //$loan['source'] = 'Solr';
                $loan['barcode'] = (string)$itemLoan->item_barcode;
                //$loan['renew'] = ;
                //$loan['renewLimit'] = ;
                //$loan['request'] = ;
                //$loan['volume'] = ;
                $loan['publication_year'] = (string)$itemLoan->publication_year;
                $loan['renewable']
                    = (strtolower((string)$itemLoan->renewable) == 'true')
                    ? true
                    : false;
                //$loan['message'] = ;
                $loan['title'] = (string)$itemLoan->title;
                $loan['item_id'] = (string)$itemLoan->loan_id;
                $loan['institution_name'] = (string)$itemLoan->library;
                //$loan['isbn'] = ;
                //$loan['issn'] = ;
                //$loan['oclc'] = ;
                //$loan['upc'] = ;
                $loan['borrowingLocation'] = (string)$itemLoan->circ_desk;

                // Calculate due status
                $dueDateTS = strtotime($loan['duedate']);
                if ($nowTS > $dueDateTS) {
                    // Loan is overdue
                    $loan['dueStatus'] = 'overdue';
                } elseif (($dueDateTS - $nowTS) < 86400) {
                    // Due date within one day
                    $loan['dueStatus'] = 'due';
                }

                $returnArray[] = $loan;
            }
        }

        return [
            'count' => $totalCount,
            'records' => $returnArray
        ];
    }

    /**
     * Get Alma loan IDs for use in renewMyItems.
     *
     * @param array $checkOutDetails An array from getMyTransactions
     *
     * @return string The Alma loan ID for this loan
     *
     * @author Michael Birkner
     */
    public function getRenewDetails($checkOutDetails)
    {
        $loanId = $checkOutDetails['item_id'];
        return $loanId;
    }

    /**
     * Renew loans via Alma API.
     *
     * @param array $renewDetails An array with the IDs of the loans returned by
     *                            getRenewDetails and the patron information
     *                            returned by patronLogin.
     *
     * @return array[] An array with the renewal details and a success or error
     *                 message.
     *
     * @author Michael Birkner
     */
    public function renewMyItems($renewDetails)
    {
        $returnArray = [];
        $patronId = $renewDetails['patron']['id'];

        foreach ($renewDetails['details'] as $loanId) {
            // Create an empty array that holds the information for a renewal
            $renewal = [];

            try {
                // POST the renewals to Alma
                $apiResult = $this->makeRequest(
                    '/users/' . $patronId . '/loans/' . $loanId . '/?op=renew',
                    [],
                    [],
                    'POST'
                );

                // Add information to the renewal array
                $blocks = false;
                $renewal[$loanId]['success'] = true;
                $renewal[$loanId]['new_date'] = $this->parseDate(
                    (string)$apiResult->due_date,
                    true
                );
                //$renewal[$loanId]['new_time'] = ;
                $renewal[$loanId]['item_id'] = (string)$apiResult->loan_id;
                $renewal[$loanId]['sysMessage'] = 'renew_success';

                // Add the renewal to the return array
                $returnArray['details'] = $renewal;
            } catch (ILSException $ilsEx) {
                // Add the empty renewal array to the return array
                $returnArray['details'] = $renewal;

                // Add a message that can be translated
                $blocks[] = 'renew_fail';
            }
        }

        $returnArray['blocks'] = $blocks;

        return $returnArray;
    }

    /**
     * Get Status
     *
     * This is responsible for retrieving the status information of a certain
     * record.
     *
     * @param string $id The record id to retrieve the holdings for
     *
     * @return mixed     On success, an associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber.
     */
    public function getStatus($id)
    {
        $idList = [$id];
        $status = $this->getStatuses($idList);
        return current($status);
    }

    /**
     * Get Statuses
     *
     * This is responsible for retrieving the status information for a
     * collection of records.
     *
     * @param array $ids The array of record ids to retrieve the status for
     *
     * @return array An array of getStatus() return values on success.
     */
    public function getStatuses($ids)
    {
        return $this->getStatusesForInventoryTypes($ids, $this->getInventoryTypes());
    }

    /**
     * Get Purchase History
     *
     * This is responsible for retrieving the acquisitions history data for the
     * specific record (usually recently received issues of a serial).
     *
     * @param string $id The record id to retrieve the info for
     *
     * @return array     An array with the acquisitions data on success.
     */
    public function getPurchaseHistory($id)
    {
        // TODO: Alma getPurchaseHistory
        return [];
    }

    /**
     * Public Function which retrieves renew, hold and cancel settings from the
     * driver ini file.
     *
     * @param string $function The name of the feature to be checked
     * @param array  $params   Optional feature-specific parameters (array)
     *
     * @return array An array with key-value pairs.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getConfig($function, $params = null)
    {
        if (isset($this->config[$function])) {
            $functionConfig = $this->config[$function];

            // Set default value for "itemLimit" in Alma driver
            if ($function === 'Holds') {
                $functionConfig['itemLimit'] = $functionConfig['itemLimit']
                    ?? 10
                    ?: 10;
            }
        } elseif ('getMyTransactions' === $function) {
            $functionConfig = [
                'max_results' => 100,
                'sort' => [
                    'checkout desc' => 'sort_checkout_date_desc',
                    'checkout asc' => 'sort_checkout_date_asc',
                    'due desc' => 'sort_due_date_desc',
                    'due asc' => 'sort_due_date_asc',
                    'title asc' => 'sort_title'
                ],
                'default_sort' => 'due asc'
            ];
        } else {
            $functionConfig = false;
        }

        return $functionConfig;
    }

    /**
     * Place a hold request via Alma API. This could be a title level request or
     * an item level request.
     *
     * @param array $holdDetails An associative array w/ atleast patron and item_id
     *
     * @return array success: bool, sysMessage: string
     *
     * @link https://developers.exlibrisgroup.com/alma/apis/bibs
     */
    public function placeHold($holdDetails)
    {
        // Check for title or item level request
        $level = $holdDetails['level'] ?? 'item';

        // Get information that is valid for both, item level requests and title
        // level requests.
        $mmsId = $holdDetails['id'];
        $holId = $holdDetails['holding_id'];
        $itmId = $holdDetails['item_id'];
        $patronId = $holdDetails['patron']['id'];
        $pickupLocation = $holdDetails['pickUpLocation'] ?? null;
        $comment = $holdDetails['comment'] ?? null;
        $requiredBy = (isset($holdDetails['requiredBy']))
        ? $this->dateConverter->convertFromDisplayDate(
            'Y-m-d',
            $holdDetails['requiredBy']
        ) . 'Z'
        : null;

        // Create body for API request
        $body = [];
        $body['request_type'] = 'HOLD';
        $body['pickup_location_type'] = 'LIBRARY';
        $body['pickup_location_library'] = $pickupLocation;
        $body['comment'] = $comment;
        $body['last_interest_date'] = $requiredBy;

        // Remove "null" values from body array
        $body = array_filter($body);

        // Check if we have a title level request or an item level request
        if ($level === 'title') {
            // Add description if we have one for title level requests as Alma
            // needs it under certain circumstances. See: https://developers.
            // exlibrisgroup.com/alma/apis/xsd/rest_user_request.xsd?tags=POST
            $description = isset($holdDetails['description']) ?? null;
            if ($description) {
                $body['description'] = $description;
            }

            // Create HTTP client with Alma API URL for title level requests
            $client = $this->httpService->createClient(
                $this->baseUrl . '/bibs/' . urlencode($mmsId)
                . '/requests?apikey=' . urlencode($this->apiKey)
                . '&user_id=' . urlencode($patronId)
                . '&format=json'
            );
        } else {
            // Create HTTP client with Alma API URL for item level requests
            $client = $this->httpService->createClient(
                $this->baseUrl . '/bibs/' . urlencode($mmsId)
                . '/holdings/' . urlencode($holId)
                . '/items/' . urlencode($itmId)
                . '/requests?apikey=' . urlencode($this->apiKey)
                . '&user_id=' . urlencode($patronId)
                . '&format=json'
            );
        }

        // Set headers
        $client->setHeaders(
            [
            'Content-type: application/json',
            'Accept: application/json'
            ]
        );

        // Set HTTP method
        $client->setMethod(\Zend\Http\Request::METHOD_POST);

        // Set body
        $client->setRawBody(json_encode($body));

        // Send API call and get response
        $response = $client->send();

        // Check for success
        if ($response->isSuccess()) {
            return ['success' => true];
        } else {
            // TODO: Throw an error
            error_log($response->getBody());
        }

        // Get error message
        $error = json_decode($response->getBody());
        if (!$error) {
            $error = simplexml_load_string($response->getBody());
        }

        return [
            'success' => false,
            'sysMessage' => $error->errorList->error[0]->errorMessage
                ?? 'hold_error_fail'
        ];
    }

    /**
     * Get Pick Up Locations
     *
     * This is responsible get a list of valid library locations for holds / recall
     * retrieval
     *
     * @param array $patron Patron information returned by the patronLogin method.
     *
     * @return array An array of associative arrays with locationID and
     * locationDisplay keys
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getPickupLocations($patron)
    {
        $xml = $this->makeRequest('/conf/libraries');
        $libraries = [];
        foreach ($xml as $library) {
            $libraries[] = [
                'locationID' => $library->code,
                'locationDisplay' => $library->name
            ];
        }
        return $libraries;
    }

    /**
     * Request from /courses.
     *
     * @return array with key = course ID, value = course name
     */
    public function getCourses()
    {
        // https://developers.exlibrisgroup.com/alma/apis/courses
        // GET /almaws/v1/courses
        $xml = $this->makeRequest('/courses');
        $courses = [];
        foreach ($xml as $course) {
            $courses[$course->id] = $course->name;
        }
        return $courses;
    }

    /**
     * Get reserves by course
     *
     * @param string $courseID     Value from getCourses
     * @param string $instructorID Value from getInstructors (not used yet)
     * @param string $departmentID Value from getDepartments (not used yet)
     *
     * @return array With key BIB_ID - The record ID of the current reserve item.
     *               Not currently used:
     *               DISPLAY_CALL_NO, AUTHOR, TITLE, PUBLISHER, PUBLISHER_DATE
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function findReserves($courseID, $instructorID, $departmentID)
    {
        // https://developers.exlibrisgroup.com/alma/apis/courses
        // GET /almaws/v1/courses/{course_id}/reading-lists
        $xml = $this->makeRequest('/courses/' . $courseID . '/reading-lists');
        $reserves = [];
        foreach ($xml as $list) {
            $listId = $list->id;
            $listXML = $this->makeRequest(
                "/courses/${$courseID}/reading-lists/${$listId}/citations"
            );
            foreach ($listXML as $citation) {
                $reserves[$citation->id] = $citation->metadata;
            }
        }
        return $reserves;
    }

    /**
     * Parse a date.
     *
     * @param string  $date     Date to parse
     * @param boolean $withTime Add time to return if available?
     *
     * @return string
     */
    public function parseDate($date, $withTime = false)
    {
        // Remove trailing Z from end of date
        // e.g. from Alma we get dates like 2012-07-13Z without time, which is wrong)
        if (strpos($date, 'T') === false && substr($date, -1) === 'Z') {
            $date = substr($date, 0, -1);
        }

        $compactDate = "/^[0-9]{8}$/"; // e. g. 20120725
        $euroName = "/^[0-9]+\/[A-Za-z]{3}\/[0-9]{4}$/"; // e. g. 13/jan/2012
        $euro = "/^[0-9]+\/[0-9]+\/[0-9]{4}$/"; // e. g. 13/7/2012
        $euroPad = "/^[0-9]{1,2}\/[0-9]{1,2}\/[0-9]{2,4}$/"; // e. g. 13/07/2012
        $datestamp = "/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/"; // e. g. 2012-07-13
        $timestamp = "/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/";
        // e. g. 2017-07-09T18:00:00

        if ($date == null || $date == '') {
            return '';
        } elseif (preg_match($compactDate, $date) === 1) {
            return $this->dateConverter->convertToDisplayDate('Ynd', $date);
        } elseif (preg_match($euroName, $date) === 1) {
            return $this->dateConverter->convertToDisplayDate('d/M/Y', $date);
        } elseif (preg_match($euro, $date) === 1) {
            return $this->dateConverter->convertToDisplayDate('d/m/Y', $date);
        } elseif (preg_match($euroPad, $date) === 1) {
            return $this->dateConverter->convertToDisplayDate('d/m/y', $date);
        } elseif (preg_match($datestamp, $date) === 1) {
            return $this->dateConverter->convertToDisplayDate('Y-m-d', $date);
        } elseif (preg_match($timestamp, $date) === 1) {
            if ($withTime) {
                return $this->dateConverter->convertToDisplayDateAndTime(
                    'Y-m-d\TH:i:sT',
                    $date
                );
            } else {
                return $this->dateConverter->convertToDisplayDate(
                    'Y-m-d',
                    substr($date, 0, 10)
                );
            }
        } else {
            throw new \Exception("Invalid date: $date");
        }
    }

    /**
     * Get the inventory types to be displayed. Possible values are:
     * p_avail,e_avail,d_avail
     *
     * @return array
     */
    protected function getInventoryTypes()
    {
        $types = explode(
            ':',
            $this->config['Holdings']['inventoryTypes']
                ?? 'physical:digital:electronic'
        );

        $result = [];
        $map = [
            'physical' => 'p_avail',
            'digital' => 'd_avail',
            'electronic' => 'e_avail'
        ];
        $types = array_flip($types);
        foreach ($map as $src => $dest) {
            if (isset($types[$src])) {
                $result[] = $dest;
            }
        }

        return $result;
    }

    /**
     * Get Statuses for inventory types
     *
     * This is responsible for retrieving the status information for a
     * collection of records with specified inventory types.
     *
     * @param array $ids   The array of record ids to retrieve the status for
     * @param array $types Inventory types
     *
     * @return array An array of getStatus() return values on success.
     */
    protected function getStatusesForInventoryTypes($ids, $types)
    {
        $results = [];
        $params = [
            'mms_id' => implode(',', $ids),
            'expand' => implode(',', $types)
        ];
        if ($bibs = $this->makeRequest('/bibs', $params)) {
            foreach ($bibs as $bib) {
                $marc = new \File_MARCXML(
                    $bib->record->asXML(),
                    \File_MARCXML::SOURCE_STRING
                );
                $status = [];
                $tmpl = [
                    'id' => (string)$bib->mms_id,
                    'source' => 'Solr',
                    'callnumber' => (string)($bib->isbn ?? ''),
                    'reserve' => 'N',
                ];
                if ($record = $marc->next()) {
                    // Physical
                    $physicalItems = $record->getFields('AVA');
                    foreach ($physicalItems as $field) {
                        $avail = $field->getSubfield('e')->getData();
                        $item = $tmpl;
                        $item['availability'] = strtolower($avail) === 'available';
                        $item['location'] = (string)$field->getSubfield('c')
                            ->getData();
                        $status[] = $item;
                    }
                    // Electronic
                    $electronicItems = $record->getFields('AVE');
                    foreach ($electronicItems as $field) {
                        $avail = $field->getSubfield('e')->getData();
                        $item = $tmpl;
                        $item['availability'] = strtolower($avail) === 'available';
                        $item['location'] = $field->getSubfield('m')->getData();
                        $url = $field->getSubfield('u')->getData();
                        if (preg_match('/^https?:\/\//', $url)) {
                            $item['locationhref'] = $url;
                        }
                        $item['status'] = $field->getSubfield('s')->getData();
                        $status[] = $item;
                    }
                    // Digital
                    $deliveryUrl
                        = $this->config['Holdings']['digitalDeliveryUrl'] ?? '';
                    $digitalItems = $record->getFields('AVD');
                    if ($digitalItems && !$deliveryUrl) {
                        $this->logWarning(
                            'Digital items exist for ' . (string)$bib->mms_id
                            . ', but digitalDeliveryUrl not set -- unable to'
                            . ' generate links'
                        );
                    }
                    foreach ($digitalItems as $field) {
                        $item = $tmpl;
                        unset($item['callnumber']);
                        $item['availability'] = true;
                        $item['location'] = $field->getSubfield('e')->getData();
                        if ($deliveryUrl) {
                            $item['locationhref'] = str_replace(
                                '%%id%%',
                                $field->getSubfield('b')->getData(),
                                $deliveryUrl
                            );
                        }
                        $status[] = $item;
                    }
                } else {
                    // TODO: Throw error
                    error_log('no record');
                }
                $results[(string)$bib->mms_id] = $status;
            }
        }
        return $results;
    }

    // @codingStandardsIgnoreStart

    /**
     * @return array with key = course ID, value = course name
     * /
     * public function getFunds() {
     * // https://developers.exlibrisgroup.com/alma/apis/acq
     * // GET /almaws/v1/acq/funds
     * }
     */

    // @codingStandardsIgnoreEnd
}
