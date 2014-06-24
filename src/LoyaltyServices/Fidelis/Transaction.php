<?php namespace LoyaltyServices\Fidelis;

class Transaction {

	public $ReturnTransactions_PHPResult;
	public $thing;

	public function __construct()
	{
		$this->thing = simplexml_load_string($this->ReturnTransactions_PHPResult);
	}
} 