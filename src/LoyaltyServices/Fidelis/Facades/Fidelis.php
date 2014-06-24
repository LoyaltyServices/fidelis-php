<?php namespace LoyaltyServices\Fidelis\Facades;

use Illuminate\Support\Facades\Facade;

class Fidelis extends Facade {

	protected static function getFacadeAccessor()
	{
		return 'fidelis';
	}
} 