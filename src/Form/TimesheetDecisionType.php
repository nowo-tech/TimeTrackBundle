<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TimeTrackBundle\Timesheet\TimesheetFormHandler;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Since 1.13: one timesheet action posted to a host route (submit, withdraw, approve, reject, reopen): the timesheet
 * id, and a comment for reject and reopen (`with_comment`). CSRF id `nowo_time_track_timesheet`; the guard decides on
 * the server ({@see TimesheetFormHandler}).
 *
 * @extends AbstractType<array{timesheet?: string|null, scope?: string|null, period?: string|null, comment?: string|null}>
 */
#[FormKitConfig(TimerActionType::FORM_KIT_PROFILE)]
final class TimesheetDecisionType extends AbstractType
{
    use FormOptionsTrait;

    public const CSRF_TOKEN_ID = 'nowo_time_track_timesheet';

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->field($builder, 'timesheet', HiddenType::class, ['label' => null]);
        // Since the GB fixes: the owner's period (submit / withdraw of a period without a stored row too).
        $this->field($builder, 'scope', HiddenType::class, ['label' => null]);
        $this->field($builder, 'period', HiddenType::class, ['label' => null]);
        if ($options['with_comment'] === true) {
            $this->field($builder, 'comment', TextareaType::class, ['label' => 'timesheet.comment', 'required' => true, 'attr' => ['maxlength' => 1000, 'minlength' => 10, 'rows' => 4]]);
        }
    }

    /**
     * CSRF id, the FormKit profile, `with_comment` (false).
     */
    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'POST',
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'csrf_token_id'      => self::CSRF_TOKEN_ID,
            'with_comment'       => false,
            'translation_domain' => 'NowoTimeTrackBundle',
        ]);
        $resolver->setAllowedTypes('with_comment', 'bool');
    }

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param class-string<FormTypeInterface<mixed>> $type
     * @param array<string, mixed> $options
     */
    private function field(FormBuilderInterface $builder, string $name, string $type, array $options): void
    {
        if (!isset($this->formOptionsMerger)) {
            $builder->add($name, $type, $options);

            return;
        }
        $this->addWithDefaults($builder, $name, $type, $options);
    }
}
