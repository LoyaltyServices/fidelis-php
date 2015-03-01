<?php namespace LoyaltyServices\Fidelis;

use Carbon\Carbon;
use DateTime;
use DateTimeZone;
use SoapFault;
use SoapClient;
use LoyaltyServices\Fidelis\Exceptions\FidelisException;

class Fidelis
{

    /**
     * @var SoapClient
     */
    private $loyaltyService;
    /**
     * @var SoapClient
     */
    private $generalService;
    /**
     * @var string
     */
    private $loyaltyServiceUrl = 'http://112.109.69.169/LSWCFService/Service.svc?wsdl';
    /**
     * @var string
     */
    private $generalServiceUrl = 'http://112.109.69.169/GENWCFService/Service.svc?wsdl';
    /**
     * @var array
     */
    private $soapOptions = [
        'exceptions' => true,
        'trace'      => true,
        'cache_wsdl' => WSDL_CACHE_NONE
    ];

    /** @var string The unique identifier for the client programme */
    private $WCF;

    /** @var string The virtual terminal ID. Required to emulate the functionality around EFTPOS terminals. */
    private $virtualTerminalId;

    /**
     * @param $WCF
     * @param $virtualTerminalId
     */
    public function __construct($WCF, $virtualTerminalId)
    {
        // Set up our SOAP services
        $this->loyaltyService = new SoapClient($this->loyaltyServiceUrl, $this->soapOptions);
        $this->generalService = new SoapClient($this->generalServiceUrl, $this->soapOptions);

        // Set our high level properties
        $this->WCF               = $WCF;
        $this->virtualTerminalId = $virtualTerminalId;
    }

    /**
     * @param string $function
     * @param array  $params
     * @param string $service
     * @param string $WCFKey
     *
     * @return \SimpleXMLElement
     * @throws FidelisException
     */
    private function makeRequest($function, $params = [], $service = 'generalService', $WCFKey = 'WCF')
    {
        $params[$WCFKey] = $this->WCF;

        try {
            $response = $this->{$service}->__soapCall($function, [$params]);
        } catch (SoapFault $e) {
            throw new FidelisException($e->getMessage(), 400);
        }

        $resultProperty = $function . 'Result';

        return json_decode(json_encode(simplexml_load_string($response->{$resultProperty})));
    }

    /////////////////////////////////////////////////////////////////////
    //// GENERAL SERVICE
    /////////////////////////////////////////////////////////////////////

    /**
     * @param DateTime $from
     * @param DateTime $to
     * @param int      $page
     *
     * @return \SimpleXMLElement
     * @throws Exceptions\FidelisException
     */
    public function getTransactionsByPage(DateTime $from = null, DateTime $to = null, $page = 1)
    {
        if (! is_null($from)) {
            $from = $from->setTimezone(new DateTimeZone('Pacific/Auckland'))->format('Y-m-d H:i:s');
        }
        if (! is_null($to)) {
            $to = $to->setTimezone(new DateTimeZone('Pacific/Auckland'))->format('Y-m-d H:i:s');
        }

        $function = 'ReturnTransactionsGeneral_PHP';
        $params   = [
            'topTen'        => 0,
            'pg'            => $page,
            'dateRangeFrom' => $from,
            'dateRangeTo'   => $to
        ];

        return $this->makeRequest($function, $params);
    }

    /**
     * @param DateTime $from
     * @param DateTime $to
     *
     * @return array
     */
    public function getTransactions(DateTime $from = null, DateTime $to = null)
    {
        $response = $this->getTransactionsByPage($from, $to);

        $meta         = $response->Table;
        $transactions = isset($response->Table1) ? $response->Table1 : [];

        if ($meta->PgCount > 1) {
            $page = 2;

            while ($page <= $meta->PgCount) {
                $response = $this->getTransactionsByPage($from, $to, $page);

                $transactions = array_merge($transactions, $response->Table1);

                $page ++;
            }
        }

        return $transactions;
    }

    /**
     * @param string|int $cardNumber    The card number to credit this transaction against
     * @param int|float  $amount        The amount of the transaction, in dollars
     * @param int        $expiresInDays Number of days until this purchase expires
     *
     * @return bool True on success
     *
     * @throws Exceptions\FidelisException
     */
    public function createPurchaseTransaction($cardNumber, $amount, $expiresInDays = null)
    {
        $function = 'LoadCardholderExpiryByDays_PHP';
        $params   = [
            'cardNumber'    => $cardNumber,
            'Amount'        => $amount,
            'CardExpiresIn' => $expiresInDays,
            'TerminalID'    => $this->virtualTerminalId
        ];

        $response = $this->makeRequest($function, $params);

        switch ($response->Table->ReturnCode) {
            case '000':
                return true;

            case '001':
                throw new FidelisException('Invalid WCF', 400);

            case '002':
                throw new FidelisException('Invalid Card Number', 400);

            case '009':
                throw new FidelisException('Web service error', 500);

            default:
                throw new FidelisException('Unknown error. Fidelis responded with: ' . $response, 500);
        }
    }

    /**
     * @param      $cardNumber
     * @param      $amount
     * @param bool $force
     *
     * @return bool
     * @throws FidelisException
     */
    public function createRedemptionTransaction($cardNumber, $amount, $force = false)
    {
        $function = 'CreateTransactionWeb_PHP';
        $params   = [
            'cardNumber'       => $cardNumber,
            'Amount'           => $amount,
            'ProcessingCode'   => '13000',
            'TerminalID'       => $this->virtualTerminalId,
            'ForceTransaction' => (int) $force
        ];

        $response = $this->makeRequest($function, $params, 'generalService', 'ClientCode');

        switch ($response->Table->ReturnCode) {
            case '000':
                return true;

            case '012':
                throw new FidelisException('Invalid transaction.');

            case '054':
                throw new FidelisException('Card Expired');

            case '031':
                throw new FidelisException('Wrong merchant');

            case '041':
                throw new FidelisException('Already loaded');

            case '039':
                throw new FidelisException('Incorrect card type');

            case '060':
                throw new FidelisException('Transaction type "13000" not allowed for card number "' . $cardNumber . '"');

            case '056':
                throw new FidelisException('Card not yet activated for redemption');

            case '051':
                throw new FidelisException('Insufficient points');

            case '094':
                throw new FidelisException('Duplicate transaction');

            case 'RV':
                throw new FidelisException('Reversal');

            default:
                throw new FidelisException('Unknown error. Fidelis responded with: ' . $response, 500);
        }
    }

    /**
     * @param $cardNumber
     *
     * @return float The points balance of the card
     */
    public function getCardBalance($cardNumber)
    {
        $function = 'CheckCardholderBalance_Email_PHP';
        $params   = [
            'cardNumber' => $cardNumber
        ];

        $response     = $this->makeRequest($function, $params);
        $responseCode = $response->Table->Column1;
        $balance      = $response->Table->Column2;

        return (float) $balance;
    }

    /**
     * @param DateTime|null $since
     *
     * @return \SimpleXMLElement
     * @throws FidelisException
     */
    public function getCardBalances(DateTime $since = null)
    {
        $function = 'ReturnAllCardholderBalancesFromDate_PHP';
        $params   = [];

        if (! is_null($since)) {
            $params = [
                'FromDate' => $since->setTimezone('Pacific/Auckland')->toW3CString()
            ];
        }

        $response = $this->makeRequest($function, $params);

        return isset($response->Table) ? $response->Table : [];
    }

    /**
     * @param $cardNumber
     *
     * @return null|\SimpleXMLElement
     */
    public function getCardholderByCardNumber($cardNumber)
    {
        // This is misnamed in the service, but correct
        $function = 'ReturnCardholderDetailsFromEmail_PHP';
        $params   = [
            'cardNumber' => $cardNumber
        ];

        $response = $this->makeRequest($function, $params);

        return isset($response->Table) ? $response->Table : null;
    }

    /**
     * @param $email
     *
     * @return null|\SimpleXMLElement[]
     */
    public function getCardholderByEmail($email)
    {
        $function = 'ReturnCardholderDetailsFromEmail_PHP';
        $params   = [
            'Cardholderemail' => $email
        ];

        $response = $this->makeRequest($function, $params);

        return isset($response->Table) ? $response->Table : null;
    }

    /**
     * @param $cardNumber
     *
     * @return int
     * @throws FidelisException
     */
    public function getVipStatus($cardNumber)
    {
        $function = 'GETVIPStatus_PHP';
        $params   = [
            'CardNumber' => $cardNumber
        ];

        $response = $this->makeRequest($function, $params);

        $returnCode = (int) $response->Table->ReturnCode;

        switch ($returnCode) {
            case 9:
                throw new FidelisException('Web service error', 500);

            case 99:
                throw new FidelisException('Invalid Card Number', 400);

            default:
                return $returnCode;
        }
    }

    /**
     * @param $cardNumber
     * @param $vipStatus
     *
     * @return bool
     * @throws FidelisException
     */
    public function setVipStatus($cardNumber, $vipStatus)
    {
        $function = 'SETVIPStatus_PHP';
        $params   = [
            'CardNumber'   => $cardNumber,
            'NewVIPStatus' => $vipStatus
        ];

        $response = $this->makeRequest($function, $params);

        $returnCode = (int) $response->Table->ReturnCode;

        switch ($returnCode) {
            case 1:
                return true;

            case 9:
                throw new FidelisException('Web service error', 500);

            case 99:
                throw new FidelisException('Invalid Card Number', 400);

            default:
                throw new FidelisException('Unknown error', 500);
        }
    }

    public function getPointsExpiringByEmail($email, $year, $month)
    {
        $function = 'CheckCardholderNextExpired';
        $params   = [
            'Email' => $email,
            // The last day of the given year/month
            'DateToExpireTo' => date('Y-m-t', strtotime($year . '=' . $month))
        ];

        $response = $this->makeRequest($function, $params);

        $returnCode = (int) $response->Table->ReturnCode;

        switch ($returnCode) {
            case 0:
                return isset($response->Table) ? $response->Table : null;

            case 1:
                throw new FidelisException('Invalid Card Number', 400);

            case 2:
                throw new FidelisException('Invalid Scripting', 500);

            default:
                throw new FidelisException('Unknown error', 500);
        }
    }
}