<?php
// {{{ICINGA_LICENSE_HEADER}}}
// {{{ICINGA_LICENSE_HEADER}}}

namespace Icinga\Forms\Config;

use InvalidArgumentException;
use Icinga\Forms\ConfigForm;
use Icinga\Web\Notification;
use Icinga\Application\Config;
use Icinga\Application\Platform;
use Icinga\Data\ConfigObject;
use Icinga\Data\ResourceFactory;
use Icinga\Exception\ConfigurationError;
use Icinga\Forms\Config\Authentication\DbBackendForm;
use Icinga\Forms\Config\Authentication\LdapBackendForm;
use Icinga\Forms\Config\Authentication\AutologinBackendForm;

class AuthenticationBackendConfigForm extends ConfigForm
{
    /**
     * The available resources split by type
     *
     * @var array
     */
    protected $resources;

    /**
     * Initialize this form
     */
    public function init()
    {
        $this->setName('form_config_authbackend');
        $this->setSubmitLabel(t('Save Changes'));
    }

    /**
     * Set the resource configuration to use
     *
     * @param   Config      $resources      The resource configuration
     *
     * @return  self
     */
    public function setResourceConfig(Config $resourceConfig)
    {
        $resources = array();
        foreach ($resourceConfig as $name => $resource) {
            $resources[strtolower($resource->type)][] = $name;
        }

        $this->resources = $resources;
        return $this;
    }

    /**
     * Return a form object for the given backend type
     *
     * @param   string      $type   The backend type for which to return a form
     *
     * @return  Form
     */
    public function getBackendForm($type)
    {
        if ($type === 'db') {
            $form = new DbBackendForm();
            $form->setResources(isset($this->resources['db']) ? $this->resources['db'] : array());
        } elseif ($type === 'ldap') {
            $form = new LdapBackendForm();
            $form->setResources(isset($this->resources['ldap']) ? $this->resources['ldap'] : array());
        } elseif ($type === 'autologin') {
            $form = new AutologinBackendForm();
        } else {
            throw new InvalidArgumentException(sprintf(t('Invalid backend type "%s" provided'), $type));
        }

        return $form;
    }

    /**
     * Add a particular authentication backend
     *
     * The backend to add is identified by the array-key `name'.
     *
     * @param   array   $values             The values to extend the configuration with
     *
     * @return  self
     *
     * @throws  InvalidArgumentException    In case the backend does already exist
     */
    public function add(array $values)
    {
        $name = isset($values['name']) ? $values['name'] : '';
        if (! $name) {
            throw new InvalidArgumentException(t('Authentication backend name missing'));
        } elseif ($this->config->hasSection($name)) {
            throw new InvalidArgumentException(t('Authentication backend already exists'));
        }

        unset($values['name']);
        $this->config->setSection($name, $values);
        return $this;
    }

    /**
     * Edit a particular authentication backend
     *
     * @param   string  $name               The name of the backend to edit
     * @param   array   $values             The values to edit the configuration with
     *
     * @return  array                       The edited backend configuration
     *
     * @throws  InvalidArgumentException    In case the backend does not exist
     */
    public function edit($name, array $values)
    {
        if (! $name) {
            throw new InvalidArgumentException(t('Old authentication backend name missing'));
        } elseif (! ($newName = isset($values['name']) ? $values['name'] : '')) {
            throw new InvalidArgumentException(t('New authentication backend name missing'));
        } elseif (! $this->config->hasSection($name)) {
            throw new InvalidArgumentException(t('Unknown authentication backend provided'));
        }

        $backendConfig = $this->config->getSection($name);
        if ($newName !== $name) {
            // Only remove the old entry if it has changed as the order gets screwed when editing backend names
            $this->config->removeSection($name);
        }

        unset($values['name']);
        $this->config->setSection($newName, $backendConfig->merge($values));
        return $backendConfig;
    }

    /**
     * Remove the given authentication backend
     *
     * @param   string      $name           The name of the backend to remove
     *
     * @return  array                       The removed backend configuration
     *
     * @throws  InvalidArgumentException    In case the backend does not exist
     */
    public function remove($name)
    {
        if (! $name) {
            throw new InvalidArgumentException(t('Authentication backend name missing'));
        } elseif (! $this->config->hasSection($name)) {
            throw new InvalidArgumentException(t('Unknown authentication backend provided'));
        }

        $backendConfig = $this->config->getSection($name);
        $this->config->removeSection($name);
        return $backendConfig;
    }

    /**
     * Move the given authentication backend up or down in order
     *
     * @param   string      $name           The name of the backend to be moved
     * @param   int         $position       The new (absolute) position of the backend
     *
     * @return  self
     *
     * @throws  InvalidArgumentException    In case the backend does not exist
     */
    public function move($name, $position)
    {
        if (! $name) {
            throw new InvalidArgumentException(t('Authentication backend name missing'));
        } elseif (! $this->config->hasSection($name)) {
            throw new InvalidArgumentException(t('Unknown authentication backend provided'));
        }

        $backendOrder = $this->config->keys();
        array_splice($backendOrder, array_search($name, $backendOrder), 1);
        array_splice($backendOrder, $position, 0, $name);

        $newConfig = array();
        foreach ($backendOrder as $backendName) {
            $newConfig[$backendName] = $this->config->getSection($backendName);
        }

        $config = Config::fromArray($newConfig);
        $this->config = $config->setConfigFile($this->config->getConfigFile());
        return $this;
    }

    /**
     * Add or edit an authentication backend and save the configuration
     *
     * Performs a connectivity validation using the submitted values. A checkbox is
     * added to the form to skip the check if it fails and redirection is aborted.
     *
     * @see Form::onSuccess()
     */
    public function onSuccess()
    {
        if (($el = $this->getElement('force_creation')) === null || false === $el->isChecked()) {
            $backendForm = $this->getBackendForm($this->getElement('type')->getValue());
            if (false === $backendForm::isValidAuthenticationBackend($this)) {
                $this->addElement($this->getForceCreationCheckbox());
                return false;
            }
        }

        $authBackend = $this->request->getQuery('auth_backend');
        try {
            if ($authBackend === null) { // create new backend
                $this->add($this->getValues());
                $message = t('Authentication backend "%s" has been successfully created');
            } else { // edit existing backend
                $this->edit($authBackend, $this->getValues());
                $message = t('Authentication backend "%s" has been successfully changed');
            }
        } catch (InvalidArgumentException $e) {
            Notification::error($e->getMessage());
            return;
        }

        if ($this->save()) {
            Notification::success(sprintf($message, $this->getElement('name')->getValue()));
        } else {
            return false;
        }
    }

    /**
     * Populate the form in case an authentication backend is being edited
     *
     * @see Form::onRequest()
     *
     * @throws  ConfigurationError      In case the backend name is missing in the request or is invalid
     */
    public function onRequest()
    {
        $authBackend = $this->request->getQuery('auth_backend');
        if ($authBackend !== null) {
            if ($authBackend === '') {
                throw new ConfigurationError(t('Authentication backend name missing'));
            } elseif (! $this->config->hasSection($authBackend)) {
                throw new ConfigurationError(t('Unknown authentication backend provided'));
            } elseif ($this->config->getSection($authBackend)->backend === null) {
                throw new ConfigurationError(sprintf(t('Backend "%s" has no `backend\' setting'), $authBackend));
            }

            $configValues = $this->config->getSection($authBackend)->toArray();
            $configValues['type'] = $configValues['backend'];
            $configValues['name'] = $authBackend;
            $this->populate($configValues);
        } elseif (empty($this->resources)) {
            $autologinBackends = array_filter(
                $this->config->toArray(),
                function ($authBackendCfg) {
                    return isset($authBackendCfg['backend']) && $authBackendCfg['backend'] === 'autologin';
                }
            );

            if (false === empty($autologinBackends)) {
                throw new ConfigurationError(t('Could not find any resources for authentication'));
            }
        }
    }

    /**
     * Return a checkbox to be displayed at the beginning of the form
     * which allows the user to skip the connection validation
     *
     * @return  Zend_Form_Element
     */
    protected function getForceCreationCheckbox()
    {
        return $this->createElement(
            'checkbox',
            'force_creation',
            array(
                'order'         => 0,
                'ignore'        => true,
                'label'         => t('Force Changes'),
                'description'   => t('Check this box to enforce changes without connectivity validation')
            )
        );
    }

    /**
     * @see Form::createElements()
     */
    public function createElements(array $formData)
    {
        $backendTypes = array();
        $backendType = isset($formData['type']) ? $formData['type'] : null;

        if (isset($this->resources['db'])) {
            $backendTypes['db'] = t('Database');
        }
        if (isset($this->resources['ldap']) && ($backendType === 'ldap' || Platform::extensionLoaded('ldap'))) {
            $backendTypes['ldap'] = 'LDAP';
        }

        $autologinBackends = array_filter(
            $this->config->toArray(),
            function ($authBackendCfg) {
                return isset($authBackendCfg['backend']) && $authBackendCfg['backend'] === 'autologin';
            }
        );
        if ($backendType === 'autologin' || empty($autologinBackends)) {
            $backendTypes['autologin'] = t('Autologin');
        }

        if ($backendType === null) {
            $backendType = key($backendTypes);
        }

        $this->addElement(
            'select',
            'type',
            array(
                'ignore'            => true,
                'required'          => true,
                'autosubmit'        => true,
                'label'             => t('Backend Type'),
                'description'       => t('The type of the resource to use for this authenticaton provider'),
                'multiOptions'      => $backendTypes
            )
        );

        if (isset($formData['force_creation']) && $formData['force_creation']) {
            // In case another error occured and the checkbox was displayed before
            $this->addElement($this->getForceCreationCheckbox());
        }

        $this->addElements($this->getBackendForm($backendType)->createElements($formData)->getElements());
    }

    /**
     * Return the configuration for the chosen resource
     *
     * @return  ConfigObject
     */
    public function getResourceConfig()
    {
        return ResourceFactory::getResourceConfig($this->getValue('resource'));
    }
}
