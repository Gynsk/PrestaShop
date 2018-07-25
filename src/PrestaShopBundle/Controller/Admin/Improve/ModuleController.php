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

namespace PrestaShopBundle\Controller\Admin\Improve;

use Exception;
use Module;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Annotation\AdminSecurity;
use PrestaShopBundle\Security\Voter\PageVoter;
use PrestaShop\PrestaShop\Core\Addon\AddonListFilter;
use PrestaShop\PrestaShop\Core\Addon\AddonListFilterOrigin;
use PrestaShop\PrestaShop\Core\Addon\AddonListFilterStatus;
use PrestaShop\PrestaShop\Core\Addon\AddonListFilterType;
use PrestaShop\PrestaShop\Core\Addon\AddonsCollection;
use PrestaShop\PrestaShop\Core\Addon\Module\ModuleRepository;
use Profile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use stdClass;

class ModuleController extends FrameworkBundleAdminController
{
    const CONTROLLER_NAME = 'ADMINMODULESSF';

    /**
     * @deprecated
     */
    const controller_name = self::CONTROLLER_NAME;

    /**
     * @AdminSecurity("is_granted(['read', 'update', 'create', 'delete'], request.get('_legacy_controller')~'_')")
     * @return Response
     */
    public function catalogAction()
    {
        return $this->render('PrestaShopBundle:Admin/Module:catalog.html.twig', array(
            'layoutHeaderToolbarBtn' => $this->getToolbarButtons(),
            'layoutTitle' => $this->trans('Module selection', 'Admin.Navigation.Menu'),
            'requireAddonsSearch' => true,
            'requireBulkActions' => false,
            'showContentHeader' => true,
            'enableSidebar' => true,
            'help_link' => $this->generateSidebarLink('AdminModules'),
            'requireFilterStatus' => false,
            'level' => $this->authorizationLevel(self::CONTROLLER_NAME),
            'errorMessage' => $this->trans(
                'You do not have permission to add this.',
                'Admin.Notifications.Error'
            ),
        ));
    }

    public function manageAction()
    {
        $modulesProvider = $this->get('prestashop.core.admin.data_provider.module_interface');
        $shopService = $this->get('prestashop.adapter.shop.context');
        $moduleRepository = $this->get('prestashop.core.admin.module.repository');
        $themeRepository = $this->get('prestashop.core.addon.theme.repository');

        // Retrieve current shop
        $shopId = $shopService->getContextShopID();
        $shops = $shopService->getShops();

        $modulesTheme = [];
        if (!empty($shopId) && is_array($shops) && isset($shops[$shopId])) {
            $shop = $shops[$shopId];
            $currentTheme = $themeRepository->getInstanceByName($shop['theme_name']);
            $modulesTheme = $currentTheme->getModulesToEnable();
        }

        $filters = new AddonListFilter();
        $filters->setType(AddonListFilterType::MODULE | AddonListFilterType::SERVICE)
            ->removeStatus(AddonListFilterStatus::UNINSTALLED);
        $installedProducts = $moduleRepository->getFilteredList($filters);

        $modules = new stdClass();
        foreach (['native_modules', 'theme_bundle', 'modules'] as $subpart) {
            $modules->{$subpart} = [];
        }

        foreach ($installedProducts as $installedProduct) {
            if (in_array($installedProduct->attributes->get('name'), $modulesTheme)) {
                $row = 'theme_bundle';
            } elseif ($installedProduct->attributes->has('origin_filter_value') &&
                      in_array(
                          $installedProduct->attributes->get('origin_filter_value'),
                          array(
                              AddonListFilterOrigin::ADDONS_NATIVE,
                              AddonListFilterOrigin::ADDONS_NATIVE_ALL,
                          )
                      ) &&
                      'PrestaShop' === $installedProduct->attributes->get('author')
            ) {
                $row = 'native_modules';
            } else {
                $row = 'modules';
            }
            $modules->{$row}[] = (object) $installedProduct;
        }

        foreach ($modules as $moduleLabel => $modulesPart) {
            $collection = AddonsCollection::createFrom($modulesPart);
            $modules->{$moduleLabel} = $modulesProvider->generateAddonsUrls($collection);
            $modules->{$moduleLabel} = $this->get('prestashop.adapter.presenter.module')->presentCollection($modulesPart);
        }

        return $this->render(
            'PrestaShopBundle:Admin/Module:manage.html.twig',
            array(
                'layoutHeaderToolbarBtn' => $this->getToolbarButtons(),
                'layoutTitle' => $this->trans('Manage installed modules', 'Admin.Modules.Feature'),
                'modules' => $modules,
                'topMenuData' => $this->getTopMenuData(
                    $this->get('prestashop.categories_provider')->getCategoriesMenu($installedProducts)
                ),
                'requireAddonsSearch' => false,
                'requireBulkActions' => true,
                'enableSidebar' => true,
                'help_link' => $this->generateSidebarLink('AdminModules'),
                'requireFilterStatus' => true,
                'level' => $this->authorizationLevel(self::CONTROLLER_NAME),
                'errorMessage' => $this->trans('You do not have permission to add this.', 'Admin.Notifications.Error'),
            )
        );
    }

    /**
     * @return Response
     */
    public function notificationAction()
    {
        $modulesPresenter = function (array $modules) {
            return $this->get('prestashop.adapter.presenter.module')->presentCollection($modules);
        };

        $moduleManager = $this->get('prestashop.module.manager');
        $modules = $moduleManager->getModulesWithNotifications($modulesPresenter);
        $layoutTitle = $this->trans('Module notifications', 'Admin.Modules.Feature');

        $errorMessage = $this->trans('You do not have permission to add this.', 'Admin.Notifications.Error');

        return $this->render(
            'PrestaShopBundle:Admin/Module:notifications.html.twig',
            array(
                'enableSidebar' => true,
                'layoutHeaderToolbarBtn' => $this->getToolbarButtons(),
                'layoutTitle' => $layoutTitle,
                'help_link' => $this->generateSidebarLink('AdminModules'),
                'modules' => $modules,
                'requireAddonsSearch' => false,
                'requireBulkActions' => false,
                'requireFilterStatus' => false,
                'level' => $this->authorizationLevel(self::CONTROLLER_NAME),
                'errorMessage' => $errorMessage,
            )
        );
    }
    /**
     * @param Request $request
     * @return Response
     */
    public function getPreferredModulesAction(Request $request)
    {
        $tabModulesList = $request->get('tab_modules_list');

        if ($tabModulesList) {
            $tabModulesList = explode(',', $tabModulesList);
            $modulesListUnsorted = $this->getModulesByInstallation(
                $tabModulesList,
                $request->request->get('admin_list_from_source')
            );
        }

        $installed = $uninstalled = [];

        if (!empty($tabModulesList)) {
            foreach ($tabModulesList as $key => $value) {
                $continue = 0;
                foreach ($modulesListUnsorted['installed'] as $moduleInstalled) {
                    if ($moduleInstalled['attributes']['name'] == $value) {
                        $continue = 1;
                        $installed[] = $moduleInstalled;
                    }
                }
                if ($continue) {
                    continue;
                }
                foreach ($modulesListUnsorted['not_installed'] as $moduleNotInstalled) {
                    if ($moduleNotInstalled['attributes']['name'] == $value) {
                        $uninstalled[] = $moduleNotInstalled;
                    }
                }
            }
        }

        $moduleListSorted = array(
            'installed' => $installed,
            'notInstalled' => $uninstalled,
        );

        $twigParams = array(
            'currentIndex' => '',
            'modulesList' => $moduleListSorted,
            'level' => $this->authorizationLevel(self::CONTROLLER_NAME),
        );

        if ($request->request->has('admin_list_from_source')) {
            $twigParams['adminListFromSource'] = $request->request->get('admin_list_from_source');
        }

        return $this->render('PrestaShopBundle:Admin/Module:tab-modules-list.html.twig', $twigParams);
    }

    private function getModulesByInstallation($modulesSelectList = null)
    {
        $addonsProvider = $this->get('prestashop.core.admin.data_provider.module_interface');
        $moduleRepository = $this->get('prestashop.core.admin.module.repository');
        $modulePresenter = $this->get('prestashop.adapter.presenter.module');
        $tabRepository = $this->get('prestashop.core.admin.tab.repository');

        $modulesOnDisk = AddonsCollection::createFrom($moduleRepository->getList());

        $modulesList = array(
            'installed' => array(),
            'not_installed' => array(),
        );

        $modulesOnDisk = $addonsProvider->generateAddonsUrls($modulesOnDisk);
        foreach ($modulesOnDisk as $module) {
            if (!isset($modulesSelectList) || in_array($module->get('name'), $modulesSelectList)) {
                $perm = true;
                if ($module->get('id')) {
                    $perm &= Module::getPermissionStatic($module->get('id'), 'configure', $this->getContext()->employee);
                } else {
                    $id_admin_module = $tabRepository->findOneIdByClassName('AdminModules');
                    $access = Profile::getProfileAccess($this->getContext()->employee->id_profile, $id_admin_module);
                    if (!$access['edit']) {
                        $perm &= false;
                    }
                }

                if ($module->get('author') === ModuleRepository::PARTNER_AUTHOR) {
                    $module->set('type', 'addonsPartner');
                }

                if ($perm) {
                    $module->fillLogo();
                    if ($module->database->get('installed') == 1) {
                        $modulesList['installed'][] = $modulePresenter->present($module);
                    } else {
                        $modulesList['not_installed'][] = $modulePresenter->present($module);
                    }
                }
            }
        }

        return $modulesList;
    }

    public function getModuleCartAction($moduleId)
    {
        $moduleRepository = $this->get('prestashop.core.admin.module.repository');
        $module = $moduleRepository->getModuleById($moduleId);

        $addOnsAdminDataProvider = $this->get('prestashop.core.admin.data_provider.module_interface');
        $collection = AddonsCollection::createFrom(array($module));
        $addOnsAdminDataProvider->generateAddonsUrls($collection);

        $modulePresenter = $this->get('prestashop.adapter.presenter.module');
        $moduleToPresent = $modulePresenter->present($module);

        return $this->render(
            '@PrestaShop/Admin/Module/Includes/modal_read_more_content.html.twig',
            array(
                'module' => $moduleToPresent,
                'level' => $this->authorizationLevel(self::CONTROLLER_NAME),
            )
        );
    }

    /**
     * Get Toolbar buttons
     *
     * @return array
     */
    protected function getToolbarButtons()
    {
        // toolbarButtons
        $toolbarButtons = [];
        if (!in_array(
            $this->authorizationLevel(self::CONTROLLER_NAME),
            array(
                PageVoter::LEVEL_READ,
                PageVoter::LEVEL_UPDATE,
            )
        )) {
            $toolbarButtons['add_module'] = [
                'href' => '#',
                'desc' => $this->trans('Upload a module', 'Admin.Modules.Feature'),
                'icon' => 'cloud_upload',
                'help' => $this->trans('Upload a module', 'Admin.Modules.Feature'),
            ];
        }

        return array_merge($toolbarButtons, $this->getAddonsConnectToolbar());
    }

    /**
     * Get addons toolbar
     *
     * @return array
     */
    protected function getAddonsConnectToolbar()
    {
        $addonsProvider = $this->get('prestashop.core.admin.data_provider.addons_interface');
        if ($addonsProvider->isAddonsAuthenticated()) {
            $addonsEmail = $addonsProvider->getAddonsEmail();

            return [
                'addons_logout' => [
                    'href' => '#',
                    'desc' => $addonsEmail['username_addons'],
                    'icon' => 'exit_to_app',
                    'help' => $this->trans('Synchronized with Addons marketplace!', 'Admin.Modules.Notification'),
                ]
            ];
        }

        return [
            'addons_connect' => [
                'href' => '#',
                'desc' => $this->trans('Connect to Addons marketplace', 'Admin.Modules.Feature'),
                'icon' => 'vpn_key',
                'help' => $this->trans('Connect to Addons marketplace', 'Admin.Modules.Feature'),
            ]
        ];
    }

    /**
     * Get top menu data
     *
     * @param array      $data       Top menu data
     * @param null|mixed $activeMenu Active menu
     *
     * @return array
     */
    protected function getTopMenuData(array $topMenuData, $activeMenu = null)
    {
        if (isset($activeMenu)) {
            if (!isset($topMenuData[$activeMenu])) {
                throw new Exception("Menu '$activeMenu' not found in Top Menu data", 1);
            }

            $topMenuData[$activeMenu]->class = 'active';
        }

        return $topMenuData;
    }
}
