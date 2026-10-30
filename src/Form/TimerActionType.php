<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Start/stop timer action of the manage UI (REQ-TWIG-005).
 *
 * Posts the same flat field names the controller always read: `_token`, `action` and `task_id`
 * (empty block prefix, created with an empty name). `id_suffix` keeps HTML ids unique when one
 * form is rendered per task row.
 *
 * A FormKit Type (REQ-TWIG-005 rule 11) on the `time_track` profile (`nowo_form_kit.profiles.time_track`, prepended by
 * the bundle extension; hosts override it there).
 *
 * @extends AbstractType<array{action?: string|null, task_id?: string|null}>
 */
#[FormKitConfig(self::FORM_KIT_PROFILE)]
final class TimerActionType extends AbstractType
{
    use FormOptionsTrait;

    /** FormKit profile of the bundle forms (key in `nowo_form_kit.profiles`). */
    public const FORM_KIT_PROFILE = 'time_track';

    public const CSRF_TOKEN_ID = 'nowo_time_track_manage';

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['action', 'task_id'] as $name) {
            if (!isset($this->formOptionsMerger)) {
                // FormKitBundle not registered in the kernel (or a plain form factory): explicit options as is.
                $builder->add($name, HiddenType::class);

                continue;
            }

            $this->addWithDefaults($builder, $name, HiddenType::class, ['label' => null]); // hidden: no FormKit convention label key
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
        $suffix = (string) $options['id_suffix'];
        if ($suffix === '') {
            return;
        }

        foreach ($view->children as $child) {
            $child->vars['id'] .= '_' . $suffix;
        }
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'          => 'POST',
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id'   => self::CSRF_TOKEN_ID,
            'id_suffix'       => '',
        ]);
        $resolver->setAllowedTypes('id_suffix', 'string');
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
