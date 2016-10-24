<?php
namespace ILab\Stem\External\Blade\Directives;

use ILab\Stem\Core\ViewDirective;

/**
 * Class MenuDirective
 *
 * Adds an `@menu` directive to Blade templates for outputting WordPress's menu
 *
 * Usage:
 * ```
 * @menu('menu-slug')
 * @menu('menu-slug',true)
 * @menu('menu-slug',true,false)
 * @menu('menu-slug',true,false,'')
 * ```
 *
 * First argument is the slug of the menu
 * Second argument is a bool that controls stripping out the ul/li wrappers WordPress adds
 * Third argument is a bool that controls if the anchor text is removed (for icon only menus, though better done with CSS)
 * Fourth argument is the class name to use for gap elements that should be inserted between menu items, blank for no gap elements
 *
 * @package ILab\Stem\External\Blade\Directives
 */
class MenuDirective extends ViewDirective {
	public function execute($args) {
		if (count($args)==0)
			throw new \Exception("Missing menu slug argument for @menu directive.");

		$slug = $args[0];
		$stripUL = (count($args)>1) ? $args[1] : false;
		$removeText = (count($args)>2) ? $args[2] : false;
		$insertGap = (count($args)>3) ? $args[3] : '';
		$array = (count($args)>4) ? $args[4] : false;

		$stripUL = ($stripUL) ? 'true' : 'false';
		$removeText = ($removeText) ? 'true' : 'false';
		$array = ($array) ? 'true' : 'false';

		$result = "<?php echo ILab\\Stem\\Core\\Context::current()->ui->menu('{$slug}',{$stripUL},'{$insertGap}',{$removeText},{$array}); ?>";
		return $result;
	}
}