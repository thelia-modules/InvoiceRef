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

namespace InvoiceRef\Form;

use InvoiceRef\InvoiceRef;
use InvoiceRef\Service\InvoiceRefSequence;
use InvoiceRef\Service\NumberedStatuses;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;
use Thelia\Model\ConfigQuery;
use Thelia\Model\OrderStatusQuery;

class ConfigurationForm extends BaseForm
{
    public function __construct(
        private readonly NumberedStatuses $numberedStatuses,
    ) {
    }

    protected function buildForm(): void
    {
        $translator = Translator::getInstance();

        $this->formBuilder
            ->add('invoice', TextType::class, [
                'constraints' => [
                    new NotBlank(),
                    new Callback(static function (mixed $value, ExecutionContextInterface $context) use ($translator): void {
                        if (\is_string($value) && '' !== trim($value) && !InvoiceRefSequence::canIncrement($value)) {
                            $context->addViolation($translator->trans('The invoice ref must end with a number.', [], InvoiceRef::DOMAIN_NAME));
                        }
                    }),
                ],
                'label' => $translator->trans('invoice ref', [], InvoiceRef::DOMAIN_NAME),
                'label_attr' => [
                    'for' => 'invoice-ref',
                ],
                'data' => ConfigQuery::read(InvoiceRefSequence::CONFIG_NAME, 0),
            ])
            ->add('statuses', ChoiceType::class, [
                'choices' => $this->statusChoices($translator->getLocale()),
                'multiple' => true,
                'expanded' => true,
                'constraints' => [
                    new Count(min: 1),
                ],
                'label' => $translator->trans('Order statuses that give the invoice number', [], InvoiceRef::DOMAIN_NAME),
                'data' => $this->numberedStatuses->codes(),
            ]);
    }

    public static function getName(): string
    {
        return 'invoiceref_configuration';
    }

    /**
     * @return array<string, string> status title => status code
     */
    private function statusChoices(string $locale): array
    {
        $choices = [];

        foreach (OrderStatusQuery::create()->orderByPosition()->find() as $status) {
            $title = $status->setLocale($locale)->getTitle();
            $label = null !== $title && '' !== $title ? \sprintf('%s (%s)', $title, $status->getCode()) : $status->getCode();
            $choices[$label] = $status->getCode();
        }

        return $choices;
    }
}
