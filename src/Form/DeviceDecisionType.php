<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TimeTrackBundle\Service\DeviceAuthorizationService;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Approve / deny form of a device request on the default verification page: hidden `decision` and `request` fields
 * (flat names, empty block prefix) and a CSRF token bound to the action and the request id
 * ({@see DeviceAuthorizationService::csrfTokenId()}, required option `csrf_token_id`).
 *
 * @extends AbstractType<array{decision?: string|null, request?: string|null}>
 */
#[FormKitConfig(TimerActionType::FORM_KIT_PROFILE)]
final class DeviceDecisionType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['decision', 'request'] as $name) {
            if (!isset($this->formOptionsMerger)) {
                $builder->add($name, HiddenType::class);

                continue;
            }

            $this->addWithDefaults($builder, $name, HiddenType::class, ['label' => null]);
        }
    }

    /**
     * @param FormView<mixed> $view
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $options
     */
    #[Override]
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        foreach ($view->children as $child) {
            $child->vars['id'] .= '_' . $options['id_suffix'];
        }
    }

    /**
     * POST, CSRF on (`csrf_token_id` required), `id_suffix` keeps the two forms' HTML ids unique.
     */
    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'          => 'POST',
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'id_suffix'       => 'decision',
        ]);
        $resolver->setRequired('csrf_token_id');
        $resolver->setAllowedTypes('csrf_token_id', 'string');
        $resolver->setAllowedTypes('id_suffix', 'string');
    }

    /**
     * Empty block prefix: the fields post flat (`_token`, `decision`, `request`).
     */
    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
