<?php

declare(strict_types=1);

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
use InvoiceRef\Service\InvoiceRefSequence;
use InvoiceRef\Service\NumberedStatuses;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\ConfigQuery;

#[Route('/admin/module/InvoiceRef', name: 'invoice_ref_configuration')]
class ConfigurationController extends BaseAdminController
{
    #[Route('/configure', name: '_configure', methods: ['POST'])]
    public function configureAction(NumberedStatuses $numberedStatuses): RedirectResponse|Response|null
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'invoiceref', AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(ConfigurationForm::getName());

        try {
            $configForm = $this->validateForm($form);

            ConfigQuery::write(InvoiceRefSequence::CONFIG_NAME, $configForm->get('invoice')->getData(), true, true);
            $numberedStatuses->save(array_values($configForm->get('statuses')->getData()));

            $this->adminLogAppend(
                'invoiceref',
                AccessManager::UPDATE,
                \sprintf(
                    'Invoice ref configuration: next number %s, numbered statuses %s',
                    $configForm->get('invoice')->getData(),
                    implode(', ', $numberedStatuses->codes()),
                ),
            );

            $request = $this->getRequest();
            $saveMode = $request->request->get('save_mode') ?? $request->query->get('save_mode');

            if ('stay' === $saveMode) {
                return $this->generateRedirectFromRoute('admin.module.configure', [], ['module_code' => 'InvoiceRef']);
            }

            return $this->generateRedirectFromRoute('admin.module');
        } catch (FormValidationException $e) {
            $errorMessage = $this->createStandardFormValidationErrorMessage($e);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
        }

        $this->setupFormErrorContext(
            'InvoiceRef Configuration',
            $errorMessage,
            $form,
            $e
        );

        return $this->render(
            'module-configure',
            ['module_code' => 'InvoiceRef']
        );
    }
}
