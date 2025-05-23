<?php
/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend_Xml
 * @copyright  Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id$
 */

 
/**
 * @category   Zend
 * @package    Zend_Xml_SecurityScan
 * @copyright  Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Zend_Xml_Security
{
    const ENTITY_DETECT = 'Detected use of ENTITY in XML, disabled to prevent XXE/XEE attacks';

    /**
     * @param integer $errno
     * @param string $errstr
     * @param string $errfile
     * @param integer $errline
     * @return bool
     */
    public static function loadXmlErrorHandler($errno, $errstr, $errfile, $errline)
    {
        if (substr_count($errstr, 'DOMDocument::loadXML()') > 0) {
            return true;
        }
        return false;
    }

    /**
     * Scan XML string for potential XXE and XEE attacks
     *
     * @param   string $xml
     * @param   DomDocument $dom
     * @throws  Zend_Xml_Exception
     * @return  SimpleXMLElement|DomDocument|boolean
     */
    public static function scan($xml, DOMDocument $dom = null)
    {
        // Omeka change, php8: libxml entity loading is always disabled by default
        $entitiesAlwaysDisabled = PHP_VERSION_ID >= 80000;

        if (null === $dom) {
            $simpleXml = true;
            $dom = new DOMDocument();
        }

        if (!$entitiesAlwaysDisabled) {
            $loadEntities = libxml_disable_entity_loader(true);
        }
        $useInternalXmlErrors = libxml_use_internal_errors(true);

        // Load XML with network access disabled (LIBXML_NONET)
        // error disabled with @ for PHP-FPM scenario
        set_error_handler(array('Zend_Xml_Security', 'loadXmlErrorHandler'), E_WARNING);

        $result = $dom->loadXml($xml, LIBXML_NONET);
        restore_error_handler();

        if (!$result) {
            // Entity load to previous setting
            if (!$entitiesAlwaysDisabled) {
                libxml_disable_entity_loader($loadEntities);
            }
            libxml_use_internal_errors($useInternalXmlErrors);
            return false;
        }

        // Scan for potential XEE attacks using ENTITY, if not PHP-FPM
        foreach ($dom->childNodes as $child) {
            if ($child->nodeType === XML_DOCUMENT_TYPE_NODE) {
                if ($child->entities->length > 0) {
                    require_once 'Exception.php';
                    throw new Zend_Xml_Exception(self::ENTITY_DETECT);
                }
            }
        }

        // Entity load to previous setting
        if (!$entitiesAlwaysDisabled) {
            libxml_disable_entity_loader($loadEntities);
        }
        libxml_use_internal_errors($useInternalXmlErrors);

        if (isset($simpleXml)) {
            $result = simplexml_import_dom($dom);
            if (!$result instanceof SimpleXMLElement) {
                return false;
            }
            return $result;
        }
        return $dom;
    }

    /**
     * Scan XML file for potential XXE/XEE attacks
     *
     * @param  string $file
     * @param  DOMDocument $dom
     * @throws Zend_Xml_Exception
     * @return SimpleXMLElement|DomDocument
     */
    public static function scanFile($file, DOMDocument $dom = null)
    {
        if (!file_exists($file)) {
            require_once 'Exception.php';
            throw new Zend_Xml_Exception(
                "The file $file specified doesn't exist"
            );
        }
        return self::scan(file_get_contents($file), $dom);
    }
}
