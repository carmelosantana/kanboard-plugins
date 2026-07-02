<?php

namespace Kanboard\Plugin\ShadcnTheme\Controller;

use Kanboard\Controller\BaseController;

/**
 * Theme Controller
 *
 * Handles theme switching functionality for the ShadcnTheme plugin.
 *
 * @package Kanboard\Plugin\ShadcnTheme\Controller
 * @author  Carmelo Santana
 */
class ThemeController extends BaseController
{
    /**
     * Set user theme preference
     *
     * @access public
     */
    public function setTheme()
    {
        $this->checkCSRFParam();
        
        $mode = $this->request->getStringParam('mode');
        $validModes = ['light', 'dark', 'system'];
        
        if (!in_array($mode, $validModes, true)) {
            $this->response->json(['error' => 'Invalid theme mode'], 400);
            return;
        }
        
        $userId = $this->userSession->getId();
        
        if ($userId) {
            // Save theme preference to user metadata
            $result = $this->userMetadataModel->save($userId, 'shadcn_theme_mode', $mode);
            
            if ($result) {
                // Update session
                $_SESSION['shadcn_theme_mode'] = $mode;
                
                $this->response->json(['success' => true, 'mode' => $mode]);
            } else {
                $this->response->json(['error' => 'Failed to save theme preference'], 500);
            }
        } else {
            // For guest users, just set session
            $_SESSION['shadcn_theme_mode'] = $mode;
            $this->response->json(['success' => true, 'mode' => $mode]);
        }
    }
    
    /**
     * Get current theme preference
     *
     * @access public
     */
    public function getTheme()
    {
        $userId = $this->userSession->getId();
        
        if ($userId) {
            $mode = $this->userMetadataModel->get($userId, 'shadcn_theme_mode', 'dark');
        } else {
            $mode = isset($_SESSION['shadcn_theme_mode']) ? $_SESSION['shadcn_theme_mode'] : 'dark';
        }
        
        $this->response->json(['mode' => $mode]);
    }
}