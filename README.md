# Introduction

HTTP/2 Server Push is a feature of the latest HTTP revision that enables the web
server to preemptively send resources to the requesting client before the client
knows that they are needed. As a result, the number of round-trips that are
required to fully load a web page may be reduced, resulting in a potentially
large performance boost (especially on slow cellular networks).

# Installation

[Click here] to download the latest release of the plugin. Once the plugin is
downloaded, navigate to 'Extensions' > 'Manage' > 'Install' on your Joomla!
site, then click on the 'Upload Package File' tab. Next, drag the downloaded
ZIP archive into the upload area to install it.

**Tip**: For extra security, make sure that the "Verified" tag appears in the
left column on the release page. This means that the release was signed and
validated using GPG.

# Configuration

Configuration for this plugin can be found on your Joomla! site under
'Extensions' > 'Plugins' by searching for 'HTTP/2 Server Push' and clicking on
the plugin name.

Once you reach the plugin configuration page, hover over the field labels for a
detailed description of each.

# Implementation Details

This project is a system plugin for Joomla! that intercepts the
[`onAfterRender`] event. Once the event is triggered, the plugin parses the HTTP
response body to find any applicable resources that can be configured for
preload or preconnect.

The plugin then sets a [`Link`] header to inform the web server about the
resulting resources. Once the web server is made aware, all (or some) of the
resources are pushed to the client.

# Credits

This plugin was originally written by [Clay Freeman] for Bluewall, LLC.

# License

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Lesser General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU Lesser General Public License for more details.

You should have received a copy of the GNU Lesser General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.

[Click here]: https://github.com/bluewallweb/plg_http2push/releases/latest
[`onAfterRender`]: https://docs.joomla.org/Plugin/Events/System#onAfterRender
[`Link`]: https://www.smashingmagazine.com/2017/04/guide-http2-server-push
[Clay Freeman]: https://github.com/clayfreeman
