<?php namespace LoyaltyServices\Fidelis;

use Carbon\Carbon;
use DateTime;
use SoapFault;
use SoapClient;
use LoyaltyServices\Fidelis\Exceptions\FidelisException;

class Fidelis {

	private $loyaltyService;
	private $generalService;
	private $loyaltyServiceUrl = 'http://112.109.69.169/LSWCFService/Service.svc?wsdl';
	private $generalServiceUrl = 'http://112.109.69.169/GENWCFService/Service.svc?wsdl';
	private $soapOptions = [
		'exceptions' => true,
		'trace'      => true,
		'cache_wsdl' => WSDL_CACHE_NONE
	];

	/** @var string The unique identifier for the client programme */
	private $WCF;

	/** @var string The virtual terminal ID. Required to emulate the functionality around EFTPOS terminals. */
	private $virtualTerminalId;

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
	 * @param $function
	 * @param $params
	 *
	 * @return \SimpleXMLElement
	 * @throws Exceptions\FidelisException
	 */
	private function makeRequest($function, $params = [], $service = 'generalService', $WCFKey = 'WCF')
	{
		$params[$WCFKey] = $this->WCF;

		try
		{
			$response = $this->{$service}->__soapCall($function, [$params]);
		}

		catch (SoapFault $e)
		{
			throw new FidelisException($e->getMessage(), 400);
		}

		$resultProperty = $function . 'Result';

		return simplexml_load_string($response->{$resultProperty});
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
		if (! is_null($from)) $from = $from->format('Y-m-d');
		if (! is_null($to)) $to = $to->format('Y-m-d');

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

		switch ($response->Table->ReturnCode)
		{
			case '000':
				return true;

			case '001':
				throw new FidelisException('Invalid WCF', 400);
				break;

			case '002':
				throw new FidelisException('Invalid Card Number', 400);
				break;

			case '009':
				throw new FidelisException('Web service error', 500);
				break;

			default:
				throw new FidelisException('Unknown error. Fidelis responded with: ' . $response, 500);
		}
	}

	public function createRedemptionTransaction($cardNumber, $amount)
	{
		$function = 'CreateTransactionWeb_PHP';
		$params   = [
			'cardNumber'     => $cardNumber,
			'Amount'         => $amount,
			'ProcessingCode' => '13000',
			'TerminalID'     => $this->virtualTerminalId
		];

		$response = $this->makeRequest($function, $params, 'generalService', 'ClientCode');

		switch ($response->Table->ReturnCode)
		{
			case '000':
				return true;

			case '012':
				throw new FidelisException('Invalid transaction.');
				break;

			case '054':
				throw new FidelisException('Card Expired');
				break;

			case '031':
				throw new FidelisException('Wrong merchant');
				break;

			case '041':
				throw new FidelisException('Already loaded');
				break;

			case '039':
				throw new FidelisException('Incorrect card type');
				break;

			case '060':
				throw new FidelisException('Transaction type "13000" not allowed for card number "' . $cardNumber . '"');
				break;

			case '056':
				throw new FidelisException('Card not yet activated for redemption');
				break;

			case '051':
				throw new FidelisException('Insufficient points');
				break;

			case '094':
				throw new FidelisException('Duplicate transaction');
				break;

			case 'RV':
				throw new FidelisException('Reversal');
				break;

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
	 * @param null $since
	 *
	 * @return \SimpleXMLElement
	 * @throws Exceptions\FidelisException
	 */
	public function getCardBalances(DateTime $since = null)
	{
		$function = 'ReturnAllCardholderBalancesFromDate_PHP';
		$params   = [];

		if (! is_null($since))
		{
			$params = [
				'FromDate' => $since->setTimezone('Pacific/Auckland')->toW3CString()
			];
		}

		$response = $this->makeRequest($function, $params);

		return $response->Table;
	}
}