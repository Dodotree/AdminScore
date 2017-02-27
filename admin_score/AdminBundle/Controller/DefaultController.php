<?php

namespace Museum\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class DefaultController extends Controller
{
    public function indexAction()
    {
        return $this->render('MuseumAdminBundle:Default:index.html.twig');
    }
}
