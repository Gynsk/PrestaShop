<?php
/**
 * 2007-2018 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2018 PrestaShop SA
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

namespace PrestaShopBundle\Controller\Api\Improve;

use PrestaShopBundle\Controller\Api\ApiController;
use PrestaShop\PrestaShop\Adapter\Module\AdminModuleDataProvider;
use PrestaShopBundle\Security\Annotation\AdminSecurity;
use PrestaShop\PrestaShop\Core\Addon\AddonListFilter;
use PrestaShop\PrestaShop\Core\Addon\AddonListFilterStatus;
use PrestaShop\PrestaShop\Core\Addon\AddonListFilterType;
use PrestaShop\PrestaShop\Core\Addon\AddonsCollection;
use PrestaShop\PrestaShop\Core\Addon\AddonListFilterOrigin;
use PrestaShop\PrestaShop\Core\Addon\Module\ModuleRepository;
use PrestaShopBundle\Entity\ModuleHistory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;
use Exception;
use DateTime;

class ModuleController extends ApiController
{
    const CONTROLLER_NAME = 'ADMINMODULESSF';

    /**
     * Controller responsible for displaying "Catalog Module Grid" section of Module management pages with ajax.
     *
     * @AdminSecurity("is_granted(['read'], request.get('_legacy_controller')~'_')")
     * @param Request $request
     *
     * @return Response
     */
    public function refreshCatalogAction(Request $request)
    {
        $modulesProvider = $this->container->get('prestashop.core.admin.data_provider.module_interface');
        $moduleRepository = $this->container->get('prestashop.core.admin.module.repository');
        $responseArray = [];

        $filters = new AddonListFilter();
        $filters->setType(AddonListFilterType::MODULE | AddonListFilterType::SERVICE)
            ->setStatus(~AddonListFilterStatus::INSTALLED)
        ;

        try {
            $modulesFromRepository = AddonsCollection::createFrom($moduleRepository->getFilteredList($filters));
            $modules = $modulesProvider->generateAddonsUrls($modulesFromRepository);

            $categoriesMenu = $this->container->get('prestashop.categories_provider')->getCategoriesMenu($modules);
            shuffle($modules);
            $responseArray['domElements'][] = $this->constructJsonCatalogBodyResponse($modulesProvider, $modules);
            $responseArray['domElements'][] = $this->constructJsonCatalogCategoriesMenuResponse($categoriesMenu);
            $responseArray['status'] = true;
        } catch (Exception $e) {
            $responseArray['msg'] = $this->trans(
                'Cannot get catalog data, please try again later. Reason: %error_details%',
                'Admin.Modules.Notification',
                array('%error_details%' => print_r($e->getMessage(), true))
            );
            $responseArray['status'] = false;
        }

        return new JsonResponse($responseArray);
    }

    /**
     * CConstruct Json catalog body response from modules provider
     *
     * @param
     *
     */
    private function constructJsonCatalogBodyResponse(AdminModuleDataProvider $modulesProvider, array $modules)
    {
        $collection = AddonsCollection::createFrom($modules);
        $modules = $modulesProvider->generateAddonsUrls($collection);
        $formattedContent = [];
        $formattedContent['selector'] = '.module-catalog-page';
        $formattedContent['content'] = $this->container->get('templating')->render(
            'PrestaShopBundle:Admin/Module/Includes:sorting.html.twig',
            ['totalModules' => count($modules)]
        );

        $formattedContent['content'] .= $this->container->get('templating')->render(
            'PrestaShopBundle:Admin/Module/Includes:grid.html.twig',
            [
                'modules' => $this->container->get('prestashop.adapter.presenter.module')->presentCollection($modules),
                'requireAddonsSearch' => true,
                'id' => 'all',
                'level' => $this->authorizationLevel($this::CONTROLLER_NAME),
                'errorMessage' => $this->trans('You do not have permission to add this.', 'Admin.Notifications.Error'),
            ]
        );

        return $formattedContent;
    }

    private function constructJsonCatalogCategoriesMenuResponse($categoriesMenu)
    {
        $formattedContent = [];
        $formattedContent['selector'] = '.module-menu-item';
        $formattedContent['content'] = $this->container->get('templating')->render(
            'PrestaShopBundle:Admin/Module/Includes:dropdown_categories.html.twig',
            ['topMenuData' => $this->getTopMenuData($categoriesMenu)]
        );

        return $formattedContent;
    }

    /**
     * @AdminSecurity("is_granted(['update', 'create', 'delete'], request.get('_legacy_controller')~'_')")
     */
    public function moduleAction(Request $request)
    {
        if ($this->isDemoModeEnabled()) {
            return $this->getDisabledFunctionalityResponse($request);
        }

        $action = $request->get('action');
        $module = $request->get('module_name');

        $moduleManager = $this->container->get('prestashop.module.manager');
        $moduleManager->setActionParams($request->request->get('actionParams', array()));
        $moduleRepository = $this->container->get('prestashop.core.admin.module.repository');
        $modulesProvider = $this->container->get('prestashop.core.admin.data_provider.module_interface');

        $response = array(
            $module => array(),
        );
        if (!method_exists($moduleManager, $action)) {
            $response[$module]['status'] = false;
            $response[$module]['msg'] = $this->trans('Invalid action', 'Admin.Notifications.Error');
            return new JsonResponse($response);
        }

        try {
            $response[$module]['status'] = $moduleManager->{$action}($module);

            if ($response[$module]['status'] === null) {
                $response[$module]['status'] = false;
                $response[$module]['msg'] = $this->trans(
                    '%module% did not return a valid response on %action% action.',
                    'Admin.Modules.Notification',
                    array(
                        '%module%' => $module,
                        '%action%' => $action,
                    )
                );
            } elseif ($response[$module]['status'] === false) {
                $error = $moduleManager->getError($module);
                $response[$module]['msg'] = $this->trans(
                    'Cannot %action% module %module%. %error_details%',
                    'Admin.Modules.Notification',
                    array(
                        '%action%' => str_replace('_', ' ', $action),
                        '%module%' => $module,
                        '%error_details%' => $error,
                    )
                );
            } else {
                $response[$module]['msg'] = $this->trans(
                    '%action% action on module %module% succeeded.',
                    'Admin.Modules.Notification',
                    array(
                        '%action%' => ucfirst(str_replace('_', ' ', $action)),
                        '%module%' => $module,
                    )
                );
            }
        } catch (UnconfirmedModuleActionException $e) {
            $collection = AddonsCollection::createFrom(array($e->getModule()));
            $modules = $modulesProvider->generateAddonsUrls($collection);
            $response[$module] = array_replace(
                $response[$module],
                array(
                    'status' => false,
                    'confirmation_subject' => $e->getSubject(),
                    'module' => $this->container->get('prestashop.adapter.presenter.module')->presentCollection($modules)[0],
                    'msg' => $this->trans(
                        'Confirmation needed by module %module% on %action% (%subject%).',
                        'Admin.Modules.Notification',
                        array(
                            '%subject%' => $e->getSubject(),
                            '%action%' => $e->getAction(),
                            '%module%' => $module,
                        )
                    )
                )
            );
        } catch (Exception $e) {
            $response[$module]['status'] = false;
            $response[$module]['msg'] = $this->trans(
                'Exception thrown by module %module% on %action%. %error_details%',
                'Admin.Modules.Notification',
                array(
                    '%action%' => str_replace('_', ' ', $action),
                    '%module%' => $module,
                    '%error_details%' => $e->getMessage(),
                )
            );

            $logger = $this->container->get('logger');
            $logger->error($response[$module]['msg']);
        }

        if ($response[$module]['status'] === true && $action != 'uninstall') {
            $moduleInstance = $moduleRepository->getModule($module);
            $collection = AddonsCollection::createFrom(array($moduleInstance));
            $moduleInstanceWithUrl = $modulesProvider->generateAddonsUrls($collection);
            $response[$module]['action_menu_html'] = $this->container->get('templating')->render(
                'PrestaShopBundle:Admin/Module/Includes:action_menu.html.twig',
                array(
                    'module' => $this->container->get('prestashop.adapter.presenter.module')->presentCollection($moduleInstanceWithUrl)[0],
                    'level' => $this->authorizationLevel($this::CONTROLLER_NAME),
                )
            );
        }

        return new JsonResponse($response);
    }

    /**
     * @return JsonResponse with number of modules having at least one notification
     */
    public function notificationsCountAction()
    {
        $moduleManager = $this->container->get('prestashop.module.manager');
        return new JsonResponse(array(
            'count' => $moduleManager->countModulesWithNotifications(),
        ));
    }

    /**
     * Controller responsible for importing new module from DropFile zone in BO.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function importModuleAction(Request $request)
    {
        $moduleManager = $this->container->get('prestashop.module.manager');
        $moduleZipManager = $this->container->get('prestashop.module.zip.manager');

        if ($this->isDemoModeEnabled()) {
            return new JsonResponse(array(
                'status' => false,
                'msg' => $this->getDemoModeErrorMessage(),
            ));
        }

        try {
            if (!in_array(
                $this->authorizationLevel($this::CONTROLLER_NAME),
                array(
                    PageVoter::LEVEL_CREATE,
                    PageVoter::LEVEL_DELETE
                )
            )
            ) {
                return new JsonResponse(
                    array(
                        'status' => false,
                        'msg' => $this->trans('You do not have permission to add this.', 'Admin.Notifications.Error'),
                    )
                );
            }
            $file_uploaded = $request->files->get('file_uploaded');
            $constraints = array(
                new Assert\NotNull(),
                new Assert\File(
                    array(
                        'maxSize'   => ini_get('upload_max_filesize'),
                        'mimeTypes' => array(
                            'application/zip',
                            'application/x-gzip',
                            'application/gzip',
                            'application/x-gtar',
                            'application/x-tgz',
                        ),
                    )
                ),
            );

            $violations = $this->container->get('validator')->validate($file_uploaded, $constraints);
            if (0 !== count($violations)) {
                $violationsMessages = '';
                foreach ($violations as $violation) {
                    $violationsMessages .= $violation->getMessage().PHP_EOL;
                }
                throw new Exception($violationsMessages);
            }

            $module_name = $moduleZipManager->getName($file_uploaded->getPathname());

            // Install the module
            $installation_response = array(
                'status' => $moduleManager->install($file_uploaded->getPathname()),
                'msg' => '',
                'module_name' => $module_name,
            );

            if ($installation_response['status'] === null) {
                $installation_response['status'] = false;
                $installation_response['msg'] = $this->trans(
                    '%module% did not return a valid response on installation.',
                    'Admin.Modules.Notification',
                    array('%module%' => $module_name)
                );
            } elseif ($installation_response['status'] === true) {
                $installation_response['msg'] = $this->trans(
                    'Installation of module %module% was successful.',
                    'Admin.Modules.Notification',
                    array('%module%' => $module_name)
                );
                $installation_response['is_configurable'] = (bool) $this->container->get('prestashop.core.admin.module.repository')
                                                          ->getModule($module_name)
                                                          ->attributes
                                                          ->get('is_configurable');
            } else {
                $error = $moduleManager->getError($module_name);
                $installation_response['msg'] = $this->trans(
                    'Installation of module %module% failed. %error%',
                    'Admin.Modules.Notification',
                    array(
                        '%module%' => $module_name,
                        '%error%' => $error,
                    )
                );
            }

            return new JsonResponse(
                $installation_response,
                200,
                array('Content-Type' => 'application/json')
            );
        } catch (UnconfirmedModuleActionException $e) {
            $collection = AddonsCollection::createFrom(array($e->getModule()));
            $modules = $this->container->get('prestashop.core.admin.data_provider.module_interface')
                     ->generateAddonsUrls($collection);
            return new JsonResponse(
                array(
                    'status' => false,
                    'confirmation_subject' => $e->getSubject(),
                    'module' => $this->container->get('prestashop.adapter.presenter.module')->presentCollection($modules)[0],
                    'msg' => $this->trans(
                        'Confirmation needed by module %module% on %action% (%subject%).',
                        'Admin.Modules.Notification',
                        array(
                            '%subject%' => $e->getSubject(),
                            '%action%' => $e->getAction(),
                            '%module%' => $module_name,
                        )
                    )
                )
            );
        } catch (Exception $e) {
            if (isset($module_name)) {
                $moduleManager->disable($module_name);
            }

            return new JsonResponse(
                array(
                'status' => false,
                'msg' => $e->getMessage(), ),
                200,
                array('Content-Type' => 'application/json')
            );
        }
    }

    public function configureModuleAction($module_name)
    {
        /* @var $legacyUrlGenerator UrlGeneratorInterface */
        $legacyUrlGenerator = $this->container->get('prestashop.core.admin.url_generator_legacy');
        $legacyContextProvider = $this->container->get('prestashop.adapter.legacy.context');
        $legacyContext = $legacyContextProvider->getContext();
        $moduleRepository = $this->container->get('prestashop.core.admin.module.repository');
        // Get accessed module object
        $moduleAccessed = $moduleRepository->getModule($module_name);

        // Get current employee ID
        $currentEmployeeID = $legacyContext->employee->id;
        // Get accessed module DB ID
        $moduleAccessedID = (int) $moduleAccessed->database->get('id');

        // Save history for this module
        $moduleHistory = $this->getDoctrine()
            ->getRepository('PrestaShopBundle:ModuleHistory')
            ->findOneBy(array(
                'idEmployee' => $currentEmployeeID,
                'idModule' => $moduleAccessedID,
            ));

        if (is_null($moduleHistory)) {
            $moduleHistory = new ModuleHistory();
        }

        $moduleHistory->setIdEmployee($currentEmployeeID);
        $moduleHistory->setIdModule($moduleAccessedID);
        $moduleHistory->setDateUpd(new DateTime(date('Y-m-d H:i:s')));

        $em = $this->getDoctrine()->getManager();
        $em->persist($moduleHistory);
        $em->flush();

        $redirectionParams = array(
            // do not transmit limit & offset: go to the first page when redirecting
            'configure' => $module_name,
        );

        return $this->redirect(
            $legacyUrlGenerator->generate('admin_module_configure_action', $redirectionParams),
            302
        );
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    protected function getDisabledFunctionalityResponse($request)
    {
        $module = $request->get('module_name');
        $content = array(
            $module => array(
                'status' => false,
                'msg' => $this->getDemoModeErrorMessage(),
            ),
        );

        return new JsonResponse($content);
    }
}
