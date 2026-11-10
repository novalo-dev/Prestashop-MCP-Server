<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to obtain
 * it through the world-wide-web, please send an email to
 * contact@novalo.fr so we can send you a copy immediately.
 *
 * @author    Novalo <contact@novalo.fr>
 * @copyright 2026 Novalo
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License v3.0 (AFL-3.0)
 */

declare(strict_types=1);

namespace Novalo\Somcp\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

// The validation checklist asks every PHP file of a module to refuse to run
// outside PrestaShop. It sits after the imports rather than at the very top
// because a namespace and its use statements have to come first, and this
// class is reached through Symfony's autoloader, not by being included.
if (!defined('_PS_VERSION_')) {
    exit;
}

/*
 * The door into the download screen, on PrestaShop 9.
 *
 * The same shape as UploadEntryController: every legacy back office page of
 * PrestaShop 9 is served by one route, and TokenizedUrlsListener demands a
 * Symfony CSRF token on any admin route whose name does not begin with "_" or
 * "api_". That token belongs to the back office session, so a link handed out
 * by an assistant cannot carry one, and an employee already signed in was met
 * with the "token compromised" warning. This route is named with the api_
 * prefix the listener leaves alone and carries no condition. The firewall still
 * applies: an employee who is not signed in is sent to the usual login form and
 * comes back here afterwards. From inside that authenticated request it builds
 * the address of the legacy page with the tokens it needs, then redirects.
 *
 * PrestaShop 8 keeps the legacy address, which its own listener lets through.
 *
 * The signed parameters travel through and are not read here: the page behind
 * checks that whoever arrives is the employee the link was signed for, on the
 * shop it was signed for, which is the check that keeps a leaked link useless.
 */
class DownloadEntryController
{
    /**
     * Sends the signed-in employee to the download screen.
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function __invoke(Request $request): RedirectResponse
    {
        // The module class is not covered by the autoloader: PrestaShop loads a
        // module's main file itself, and this controller runs before anything
        // has asked it to.
        $module = \Module::getInstanceByName('somcp');
        if (!$module instanceof \Somcp) {
            return new RedirectResponse(\Tools::getShopDomainSsl(true));
        }

        \Somcp::bootClasses();

        $employee = \Somcp::legacyContext()->employee;
        $idEmployee = ($employee && $employee->id) ? (int) $employee->id : 0;

        // The firewall guarantees an employee here. Should that ever stop being
        // true, the back office root is a safe place to land - never the
        // download screen, which would then be reached without anyone being
        // identified.
        if ($idEmployee <= 0) {
            return new RedirectResponse(\SomcpBackOffice::adminBaseUrl());
        }

        return new RedirectResponse(\SomcpBackOffice::legacyUrl(
            \SomcpBackOffice::DOWNLOAD_CONTROLLER,
            $idEmployee,
            \SomcpBackOffice::symfonyToken(),
            [
                'kind' => (string) $request->query->get('kind', ''),
                'id' => (string) $request->query->get('id', ''),
                'e' => (string) $request->query->get('e', ''),
                's' => (string) $request->query->get('s', ''),
            ]
        ));
    }
}
