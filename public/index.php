<?php
// {{{ICINGA_LICENSE_HEADER}}}
// {{{ICINGA_LICENSE_HEADER}}}

require_once dirname(__FILE__). '/../library/Icinga/Application/ApplicationBootstrap.php';
require_once dirname(__FILE__). '/../library/Icinga/Application/Web.php';

use Icinga\Application\Web;

Web::start()->dispatch();
