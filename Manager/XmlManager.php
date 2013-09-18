<?php

namespace Leclerc\OperationBeauteBundle\Manager;

use Doctrine\ORM\EntityManager;
use Leclerc\OperationBeauteBundle\Manager\BaseManager;

use Leclerc\OperationBeauteBundle\Entity\Product;
use Leclerc\OperationBeauteBundle\Entity\Brand;
use Leclerc\OperationBeauteBundle\Entity\Category;

class XmlManager extends BaseManager
{
    protected $parser;

    public function __construct($data, $options, $data_is_url, $ns, $is_prefix)
    {
        $this->parser = new SimpleXMLElement($data, $options, $data_is_url, $ns, $is_prefix);
    }

    
    public function hydrateProduct()
    {
        
    }
    
    public function hydrateBrand()
    {
        
    }
}