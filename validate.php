<?php
/**
 * This function makes use of dnsProtocol class, which is able to retrieve DNS records from nameservers
 * With this information the function validateDomain is able to check if the DNSSEC information on a domain name is valid
 */
include_once("vendor/autoload.php");


function validateDomain($domainname)
{
    $parentkeys = null;
    $domainname = strtolower($domainname);
    $dns = new Metaregistrar\DNS\dnsProtocol(false);
    $tld = substr($domainname,strpos($domainname,'.')+1);
    $dnsservers = $dns->registrynameservers($tld);
    if (!is_array($dnsservers))
    {
        throw new Metaregistrar\DNS\dnsException("DNSSEC validation not supported yet for the domain name ".$domainname);
    }
    foreach ($dnsservers as $dnsserver)
    {
        $dns->setServer($dnsserver);
        $result = $dns->Query($domainname,'NS');
        if ($result->getNameserverResultCount()>0)
        {
            $ns = $result->getNameserverResults();
            foreach ($ns as $n)
            {
                /* @var $n Metaregistrar\DNS\dnsNSresult */
                $nameservers[]=$n->getNameserver();
            }
            $result = $dns->Query($domainname,'DS');
            if ($result->getResourceResultCount()==0)
            {
                #
                # No DS record found at parent: domain is not secured
                #
                throw new Metaregistrar\DNS\dnsException("No DS record found at parent: Domainname is not secured");
            }
            else
            {
                $ds = $result->getResourceResults();
                foreach ($ds as $d)
                {
                    /* @var $d Metaregistrar\DNS\dnsDSresult */
                    $pk['key']=$d->getKey();
                    $pk['keytag']=$d->getKeytag();
                    $pk['algorithm']=$d->getAlgorithm();
                    $pk['matched']=false;
                    $parentkeys[]=$pk;
                }
            }
            break;
        }
    }

    #
    # Retrieve all necessary records
    #
    if (isset($nameservers) && is_array($nameservers)) {
        foreach ($nameservers as $ns)
        {
            $dns->setServer($ns);
            $result = $dns->Query($domainname,'RRSIG');
            if ($result->getResourceResultCount()==0)
            {
                throw new Metaregistrar\DNS\dnsException("No RRSIG records found on ".$ns." for domain name ".$domainname);
            }
            else
            {
                $rrsigs = $result->getResourceResults();
                if (is_array($rrsigs)) {
                    foreach ($rrsigs as $rrsig)
                    {
                        /* @var $rrsig Metaregistrar\DNS\dnsRRSIGresult */
                        if ($rrsig->getTypeCovered()=='SOA')
                        {
                            $rr[$ns]=$rrsig;
                        }
                    }
                }
            }
            $result2 = $dns->Query($domainname,'DNSKEY');
            if ($result2->getResourceResultCount()==0)
            {
                throw new Metaregistrar\DNS\dnsException("No DNSKEY records found on ".$ns." for domain name ".$domainname);
            }
            else
            {
                $ds = $result2->getResourceResults();
                foreach ($ds as $childkey)
                {
                    /* @var $childkey Metaregistrar\DNS\dnsDNSKEYresult */
                    if ($childkey->getSep())
                    {
                        $dnskey[$ns]=$childkey;
                    }
                }
            }
            if ((!isset($rr)) || (!$rr[$ns]))
            {
                throw new Metaregistrar\DNS\dnsException("No matching resource record type SOA found on ".$ns." for ".$domainname);
            }
            if ((!isset($dnskey)) || (!$dnskey[$ns]))
            {
                throw new Metaregistrar\DNS\dnsException("No matching DNSKEY record found with SEP flag enabled on ".$ns." for $domainname");
            }
            validateRRSIG($domainname, $rr[$ns], $ds);
            validateDNSKEY($domainname, $dnskey[$ns], $parentkeys);
        }
    }
    return true;
}


function validateDNSKEY($domainname, Metaregistrar\DNS\dnsDNSKEYresult $dnskey, $parentkeys)
{
    $validkeyfound = false;
    foreach ($parentkeys as $index=>$parentkey)
    {
        if ($dnskey->getKeytag()==$parentkey['keytag'])
        {
            #
            # Algorithms for SEP key and parent key must match
            #
            $validkeyfound = true;
            $parentkeys[$index]['matched']=true;
            if ($parentkey['algorithm']!=$dnskey->getAlgorithm())
            {
                throw new Metaregistrar\DNS\dnsException("Parent ($parentkey[algorithm]) and child (".$dnskey->getAlgorithm().") algorithms for key ".$dnskey->getKeytag()." do not match for ".$domainname);
            }
        }
        else
        {
            //$algo = $dnskey->algorithm($dnskey->getAlgorithm());
        }

    }
    foreach ($parentkeys as $parentkey)
    {
        if (!$parentkey['matched'])
        {
            throw new Metaregistrar\DNS\dnsException('No match found for parent key '.$parentkey['keytag']);
        }
    }
    if (!$validkeyfound)
    {
        throw new Metaregistrar\DNS\dnsException("No valid key with SEP found for domain name ".$domainname);
    }
}


function validateRRSIG($domainname, Metaregistrar\DNS\dnsRRSIGresult $rrsig, $ds)
{
    #
    # Inception timestamp must lie in the past
    #
    if ($rrsig->getInceptionTimestamp() > time())
    {

        throw new Metaregistrar\DNS\dnsException("Key ".$rrsig->getKeytag()." for domain name ".$domainname." is not yet valid: starts on ".$rrsig->getInceptionDate());
    }
    #
    # Expiration timestamp must lie in the future
    #
    if ($rrsig->getExpirationTimestamp() < time())
    {
        throw new Metaregistrar\DNS\dnsException("Key ".$rrsig->getKeytag()." for domain name ".$domainname." has expired at ".$rrsig->getExpirationDate());
    }
    #
    # Signer name must be equal to domain name
    #
    if ($rrsig->getSignername()!=$domainname)
    {
        throw new Metaregistrar\DNS\dnsException("RRSIG signer name ".$rrsig->getSignername()." for domain name ".$domainname." is incorrect");
    }
    #
    # Keytag for signing must exist in the DNSKEY records
    #
    $keyfound = false;
    if (is_array($ds)) {
        foreach ($ds as $childkey)
        {
            /* @var $childkey Metaregistrar\DNS\dnsRRSIGresult */
            if ($childkey->getKeytag()==$rrsig->getKeytag())
            {
                $keyfound = true;
            }
        }
    }
    if (!$keyfound)
    {
        throw new Metaregistrar\DNS\dnsException("Keytag ".$rrsig->getKeytag()." cannot be found in the DNSKEY records for domain name ".$domainname." to validate RRSIG");
    }
}
