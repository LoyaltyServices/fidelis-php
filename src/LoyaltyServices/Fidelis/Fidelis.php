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
		'trace'      => true
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
	private function makeRequest($function, $params)
	{
		$params['WCF'] = $this->WCF;

		try
		{
			$response = $this->generalService->__soapCall($function, [$params]);
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
			case 0:
				return true;

			case 1:
				throw new FidelisException('Invalid WCF', 400);
				break;

			case 2:
				throw new FidelisException('Invalid Card Number', 400);
				break;

			case 9:
				throw new FidelisException('Web service error', 500);
				break;

			default:
				throw new FidelisException('Unknown error. Fidelis responded with: ' . $response, 500);
		}
	}

	public function createRefundTransaction($cardNumber, $amount)
	{
		$function = 'CreateTransactionWeb_PHP';
		$params   = [
			'cardNumber'     => $cardNumber,
			'Amount'         => $amount,
			'ProcessingCode' => '13000',
			'TerminalID'     => $this->virtualTerminalId
		];

		$response = $this->makeRequest($function, $params);

		switch ($response->Table->ResponseCode)
		{
			case '00':
				return true;

			default:
				throw new FidelisException('Unknown error. Fidelis responded with: ' . $response, 500);
		}
	}

	/**
	 * @param $cardNumber
	 *
	 * @return float The points balance of the card
	 */
	public function getBalanceForCard($cardNumber)
	{
		// Do the thing
	}
}