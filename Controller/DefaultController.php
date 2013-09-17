<?php

namespace Maesbox\VideoBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class DefaultController extends Controller
{
    public function indexAction($name)
    {
        return $this->render('MaesboxVideoBundle:Default:index.html.twig', array('name' => $name));
    }
}
