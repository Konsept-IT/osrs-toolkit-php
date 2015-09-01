<?php

use OpenSRS\domains\lookup\GetDomainsContacts;

class GetDomainsContactsTest extends PHPUnit_Framework_TestCase
{
    /**
     * New NameSuggest should throw an exception if 
     * data->domain is ommitted 
     * 
     * @return void
     */
    public function testValidateMissingDomainList()
    {
        $data = (object) array (
            'func' => 'lookupGetDomainsContacts',
            'data' => (object) array (
                // 'domain' => 'hockey.com'
                ),
            );

        $this->setExpectedException('OpenSRS\Exception');
        $ns = new GetDomainsContacts('array', $data);
    }
}
