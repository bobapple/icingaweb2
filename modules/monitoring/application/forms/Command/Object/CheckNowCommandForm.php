<?php
// {{{ICINGA_LICENSE_HEADER}}}
// {{{ICINGA_LICENSE_HEADER}}}

namespace Icinga\Module\Monitoring\Forms\Command\Object;

use Icinga\Module\Monitoring\Command\Object\ScheduleHostCheckCommand;
use Icinga\Module\Monitoring\Command\Object\ScheduleServiceCheckCommand;
use Icinga\Web\Notification;

/**
 * Form for immediately checking hosts or services
 */
class CheckNowCommandForm extends ObjectsCommandForm
{
    /**
     * (non-PHPDoc)
     * @see \Zend_Form::init() For the method documentation.
     */
    public function init()
    {
        $this->setAttrib('class', 'inline link-like');
    }

    /**
     * (non-PHPDoc)
     * @see \Icinga\Web\Form::addSubmitButton() For the method documentation.
     */
    public function addSubmitButton()
    {
        $iconUrl = $this->getView()->href('img/icons/refresh_petrol.png');

        $this->addElements(array(
            array(
                'button',
                'btn_submit',
                array(
                    'ignore'        => true,
                    'type'          => 'submit',
                    'value'         => mt('monitoring', 'Check now'),
                    'label'         => '<img src="' . $iconUrl . '"> ' . mt('monitoring', 'Check now'),
                    'decorators'    => array('ViewHelper'),
                    'escape'        => false,
                    'class'         => 'link-like'
                )
            )
        ));

        return $this;
    }

    /**
     * (non-PHPDoc)
     * @see \Icinga\Web\Form::onSuccess() For the method documentation.
     */
    public function onSuccess()
    {
        foreach ($this->objects as $object) {
            /** @var \Icinga\Module\Monitoring\Object\MonitoredObject $object */
            if ($object->getType() === $object::TYPE_HOST) {
                $check = new ScheduleHostCheckCommand();
            } else {
                $check = new ScheduleServiceCheckCommand();
            }
            $check
                ->setObject($object)
                ->setForced()
                ->setCheckTime(time());
            $this->getTransport($this->request)->send($check);
        }
        Notification::success(mtp(
            'monitoring',
            'Scheduling check..',
            'Scheduling checks..',
            count($this->objects)
        ));
        return true;
    }
}
