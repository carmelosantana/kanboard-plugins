<ul class="modmenu-tabs">
    <li><?= $this->url->link(t('Installed'), 'ModMenuController', 'show', ['plugin' => 'ModMenu'], false, $tab === 'installed' ? 'active' : '') ?></li>
    <li><?= $this->url->link(t('Browse'), 'ModMenuController', 'directory', ['plugin' => 'ModMenu'], false, $tab === 'browse' ? 'active' : '') ?></li>
    <li><?= $this->url->link(t('Upload'), 'UploadController', 'upload', ['plugin' => 'ModMenu'], false, $tab === 'upload' ? 'active' : '') ?></li>
    <li><?= $this->url->link(t('Sources'), 'ModMenuController', 'sources', ['plugin' => 'ModMenu'], false, $tab === 'sources' ? 'active' : '') ?></li>
</ul>
