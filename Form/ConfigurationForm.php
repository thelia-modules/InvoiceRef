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

namespace InvoiceRef\Form;

use InvoiceRef\InvoiceRef;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;
use Thelia\Model\ConfigQuery;

/**
 * Class ConfigurationForm.
 *
 * @author manuel raynaud <mraynaud@openstudio.fr>
 */
class ConfigurationForm extends BaseForm
{
    /**
     * in this function you add all the fields you need for your Form.
     * Form this you have to call add method on $this->formBuilder attribute :.
     *
     * $this->formBuilder->add("name", "text")
     *   ->add("email", "email", array(
     *           "attr" => array(
     *               "class" => "field"
     *           ),
     *           "label" => "email",
     *           "constraints" => array(
     *               new \Symfony\Component\Validator\Constraints\NotBlank()
     *           )
     *       )
     *   )
     *   ->add('age', 'integer');
     *
     * @return null
     */
    protected function buildForm()
    {
        $this->formBuilder
            ->add('invoice', TextType::class, [
                'constraints' => [
                    new NotBlank(),
                ],
                'label' => Translator::getInstance()->trans('invoice ref', [], InvoiceRef::DOMAIN_NAME),
                'label_attr' => [
                    'for' => 'invoice-ref',
                ],
                'data' => ConfigQuery::read('invoiceRef', 0),
            ]);
    }

    /**
     * @return string the name of you form. This name must be unique
     */
    public static function getName()
    {
        return 'invoiceref_configuration';
    }
}
