<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace InvoiceRef\Controller;

use InvoiceRef\Form\ConfigurationForm;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\ConfigQuery;

/**
 * Class ConfigurationController
 *
 * @package InvoiceRef\Controller
 * @author  manuel raynaud <mraynaud@openstudio.fr>
 */
#[Route('/admin/module/InvoiceRef', name: 'invoice_ref_configuration')]
class ConfigurationController extends BaseAdminController
{
    #[Route('/configure', name: '_configure', methods: ['POST'])]
    public function configureAction(): RedirectResponse|Response|null
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'invoiceref', AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(ConfigurationForm::getName());

        try {
            $configForm = $this->validateForm($form);

            ConfigQuery::write('invoiceRef', $configForm->get('invoice')->getData(), true, true);

            $request = $this->getRequest();
            $saveMode = $request->request->get('save_mode') ?? $request->query->get('save_mode');

            if ($saveMode === 'stay') {
                return $this->generateRedirectFromRoute('admin.module.configure', [], ['module_code' => 'InvoiceRef']);
            }

            return $this->generateRedirectFromRoute('admin.module');
        } catch (FormValidationException $e) {
            $error_msg = $this->createStandardFormValidationErrorMessage($e);
        } catch (\Exception $e) {
            $error_msg = $e->getMessage();
        }

        $this->setupFormErrorContext(
            'InvoiceRef Configuration',
            $error_msg,
            $form,
            $e
        );

        return $this->render(
            'module-configure',
            ['module_code' => 'InvoiceRef']
        );
    }
}
