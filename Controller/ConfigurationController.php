<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace InvoiceRef\Controller;

use InvoiceRef\Form\ConfigurationForm;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\ConfigQuery;
use Thelia\Tools\URL;

/**
 * Class ConfigurationController
 * @package InvoiceRef\Controller
 * @author manuel raynaud <mraynaud@openstudio.fr>
 */
class ConfigurationController extends BaseAdminController
{

    public function configureAction()
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'invoiceref', AccessManager::UPDATE)) {
            return $response;
        }

        $form = new ConfigurationForm($this->getRequest());

        $response = $error_msg = $e = null;

        try {
            $configForm = $this->validateForm($form);

            ConfigQuery::write('invoiceRef', $configForm->get('invoice')->getData(), true, true);

            // Redirect to the success URL,
            if ($this->getRequest()->get('save_mode') === 'stay') {
                // If we have to stay on the same page, redisplay the configuration page/
                $route = '/admin/module/InvoiceRef';
            } else {
                // If we have to close the page, go back to the module back-office page.
                $route = '/admin/modules';
            }
            $response = RedirectResponse::create(URL::getInstance()->absoluteUrl($route));
        } catch (FormValidationException $e) {
            $error_msg = $this->createStandardFormValidationErrorMessage($e);
        } catch (\Exception $e) {
            $error_msg = $e->getMessage();
        }

        if (null !== $error_msg) {
            $this->setupFormErrorContext(
                'InvoiceRef Configuration',
                $error_msg,
                $form,
                $e
            );
            $response = $this->render(
                'module-configure',
                ['module_code' => 'InvoiceRef']
            );
        }
        return $response;
    }
}
