<?php

interface poller {
	public function __construct($iphost,$user='',$password='',$options=array());
	public function retrieve();
	public function getData();
}
