# Attribution and third-party material

Dev-Jack maintains this implementation at [JacksCodeVault/ros-plugin](https://github.com/JacksCodeVault/ros-plugin). Changing the manifest author identifies the current maintainer; it does not transfer ownership of inherited code.

## Original ROS plugin

This project builds on [Madalitso Mzandu's UISP ROS plugin](https://github.com/MadaMzandu/uisp-ros-plugin). Preserve its authorship and any applicable original copyright and license notices.

The imported source in this checkout does not include an upstream license file. The maintainer has confirmed possession of a license authorizing publication and redistribution of the inherited implementation. That confirmation does not transfer authorship or make inherited material part of Dev-Jack's MIT-licensed original contributions. The maintainer is responsible for retaining the underlying license evidence and complying with its terms.

## RouterOS PHP API client

`lib/routeros_api.class.php` identifies Denis Basta as its author and Nick Barnes, Ben Menking, Jeremy Jefferson, Cristian Deluxe, and Mikhail Moskalev as contributors. Its original header is retained. Relevant upstream: [BenMenking/routeros-api](https://github.com/BenMenking/routeros-api).

The current upstream [license file](https://github.com/BenMenking/routeros-api/blob/master/LICENSE) contains MIT terms credited to Denis Basta and contributors. Verify that those terms cover the specific imported client version before redistribution; do not infer its license from the application's Composer metadata. Retain the applicable upstream license text alongside this notice once verified.

## Composer dependencies

The currently locked Composer packages declare MIT in `composer.lock`, including the Ubiquiti UCRM Plugin SDK. Their own copyright and license files govern those packages. Distribute their license files with `vendor/` in release archives. Recheck notices whenever dependencies change.

## Names and artwork

UISP and Ubiquiti are names associated with Ubiquiti; MikroTik and RouterOS are names associated with MikroTik. This is an independent community project, not an official product of either company.

`public/panel/MT_Symbol_Black.png` is supplied MikroTik brand artwork. The maintainer has confirmed that MikroTik permits its inclusion and has elected to distribute the supplied file with this plugin. The artwork is not MIT-licensed, its inclusion grants no trademark rights, and it must continue to be used consistently with any applicable MikroTik branding conditions. Do not replace it with invented vendor artwork.
