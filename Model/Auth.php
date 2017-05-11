<?php
/**
 * MageSpecialist
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to info@magespecialist.it so we can send you a copy immediately.
 *
 * @category   MSP
 * @package    MSP_Passwd
 * @copyright  Copyright (c) 2017 Skeeller srl (http://www.magespecialist.it)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace MSP\Passwd\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use MSP\Passwd\Api\AuthInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Action\Action;

class Auth implements AuthInterface
{
    /**
     * @var ResponseInterface
     */
    private $response;

    /**
     * @var ActionFlag
     */
    private $actionFlag;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    public function __construct(
        ResponseInterface $response,
        ActionFlag $actionFlag,
        ScopeConfigInterface $scopeConfig
    )
    {
        $this->response = $response;
        $this->actionFlag = $actionFlag;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Return true if access is authorized
     * @param Http $request
     * @param string $area
     * @return bool
     */
    public function isAuthorized(Http $request, $area)
    {
        $enabled = !!$this->scopeConfig->getValue(
            $area == AuthInterface::AREA_FRONTEND ?
                AuthInterface::XML_PATH_FRONTEND_ENABLED :
                AuthInterface::XML_PATH_BACKEND_ENABLED
        );

        if ($enabled) {
            $clientIp = $request->getClientIp();
            $whitelist = $this->getAllowedRanges($area);

            $user = $this->scopeConfig->getValue(
                $area == AuthInterface::AREA_FRONTEND ?
                    AuthInterface::XML_PATH_FRONTEND_USER :
                    AuthInterface::XML_PATH_BACKEND_USER
            );
            $pass = $this->scopeConfig->getValue(
                $area == AuthInterface::AREA_FRONTEND ?
                    AuthInterface::XML_PATH_FRONTEND_PASS :
                    AuthInterface::XML_PATH_BACKEND_PASS
            );

            if ($this->getIpIsMatched($clientIp, $whitelist)) {
                return true;
            }

            $realm = $this->getRealm($area);
            $digest = $this->getHttpDigestParse($request->getServer('PHP_AUTH_DIGEST'));
            if ($digest) {
                if ($digest['username'] == $user) {
                    $a1 = md5($digest['username'] . ':' . $realm . ':' . $pass);
                    $a2 = md5($request->getServer('REQUEST_METHOD') . ':' . $digest['uri']);
                    $validResponse = md5(
                        $a1 . ':' .
                        $digest['nonce'] . ':' .
                        $digest['nc'] . ':' .
                        $digest['cnonce'] . ':' .
                        $digest['qop'] . ':' .
                        $a2
                    );

                    if ($digest['response'] == $validResponse) {
                        return true;
                    }
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Get auth realm
     * @param string $area
     * @return string
     */
    private function getRealm($area)
    {
        return "MSP Security Suite - " . $area;
    }

    /**
     * Get auth digest
     * @param string $authDigest
     * @return array|bool
     */
    private function getHttpDigestParse($authDigest)
    {
        $needed_parts = [
            'nonce' => 1,
            'nc' => 1,
            'cnonce' => 1,
            'qop' => 1,
            'username' => 1,
            'uri' => 1,
            'response' => 1
        ];
        $data = [];
        $keys = implode('|', array_keys($needed_parts));

        preg_match_all('@(' . $keys . ')=(?:([\'"])([^\2]+?)\2|([^\s,]+))@', $authDigest, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $data[$m[1]] = $m[3] ? $m[3] : $m[4];
            unset($needed_parts[$m[1]]);
        }

        return $needed_parts ? false : $data;
    }

    /**
     * Return true if access is authorized
     * @param Http $request
     * @param string $area
     * @return void
     */
    public function requireAuth(Http $request, $area)
    {
        if (!$this->isAuthorized($request, $area)) {
            $realm = $this->getRealm($area);

            $this->response->setHttpResponseCode(401);
            $this->response->setHeader(
                'WWW-Authenticate',
                'Digest realm="' . $realm . '",qop=auth,nonce="' . uniqid() . '",opaque="' . md5($realm) . '"'
            );
            $this->response->setBody('<h1>Unauthorized</h1>');

            $this->response->sendHeaders();
            $this->response->sendResponse();
            $this->actionFlag->set('', Action::FLAG_NO_DISPATCH, true);
        }
    }

    /**
     * Return true if IP is in range
     * @param  $ip
     * @param  $range
     * @return bool
     */
    private function getIpInRange($ip, $range)
    {
        if (strpos($range, '/') === false) {
            $range .= '/32';
        }

        list($range, $netmask) = explode('/', $range, 2);
        $rangeDecimal = ip2long($range);
        $ipDecimal = ip2long($ip);
        $wildcardDecimal = pow(2, (32 - $netmask)) - 1;
        $netmaskDecimal = ~$wildcardDecimal;

        return (bool)(($ipDecimal & $netmaskDecimal) == ($rangeDecimal & $netmaskDecimal));
    }

    /**
     * Return true if IP is matched in a range list
     * @param  $ip
     * @param  array $ranges
     * @return bool
     */
    private function getIpIsMatched($ip, array $ranges)
    {
        foreach ($ranges as $range) {
            if ($this->getIpInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return a list of allowed IPs
     * @param $area
     * @return array
     */
    private function getAllowedRanges($area)
    {
        if ($area == AuthInterface::AREA_BACKEND) {
            $ranges = $this->scopeConfig->getValue(AuthInterface::XML_PATH_BACKEND_IP_WHITELIST);
        } else {
            $ranges = $this->scopeConfig->getValue(AuthInterface::XML_PATH_FRONTEND_IP_WHITELIST);
        }

        return preg_split('/\s*[,;]+\s*/', $ranges);
    }
}
