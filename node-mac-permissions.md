[![MIT license](https://img.shields.io/badge/License-MIT-blue.svg)](https://lbesson.mit-license.org/)
 [![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg?style=flat-square)](http://makeapullrequest.com) [![Actions Status](https://github.com/codebytere/node-mac-permissions/workflows/Test/badge.svg)](https://github.com/codebytere/node-mac-permissions/actions)

# node-mac-permissions

## Table of Contents

- [Overview](#overview)
- [API](#api)
  - [`permissions.getAuthStatus(type)`](#permissionsgetauthstatustype)
  - [`permissions.askForAccessibilityAccess()`](#permissionsaskforaccessibilityaccess)
  - [`permissions.askForAppleEventsAccess()`](#permissionsaskforappleeventsaccesstargetappbundleid)
  - [`permissions.askForContactsAccess()`](#permissionsaskforcontactsaccess)
  - [`permissions.askForCalendarAccess([accessLevel])`](#permissionsaskforcalendaraccessaccesslevel)
  - [`permissions.askForSpeechRecognitionAccess()`](#permissionsaskforspeechrecognitionaccess)
  - [`permissions.askForRemindersAccess()`](#permissionsaskforremindersaccess)
  - [`permissions.askForFoldersAccess(folder)`](#permissionsaskforfoldersaccessfolder)
  - [`permissions.askForFullDiskAccess()`](#permissionsaskforfulldiskaccess)
  - [`permissions.askForCameraAccess()`](#permissionsaskforcameraaccess)
  - [`permissions.askForLocationAccess([accessLevel])](#permissionsaskforlocationaccessaccesslevel)
  - [`permissions.askForInputMonitoringAccess([accessLevel])`](#permissionsaskforinputmonitoringaccessaccesslevel)
  - [`permissions.askForMicrophoneAccess()`](#permissionsaskformicrophoneaccess)
  - [`permissions.askForMusicLibraryAccess()`](#permissionsaskformusiclibraryaccess)
  - [`permissions.askForPhotosAccess([accessLevel])`](#permissionsaskforphotosaccessaccesslevel)
  - [`permissions.askForScreenCaptureAccess([openPreferences])`](#permissionsaskforscreencaptureaccessopenpreferences)
- [FAQ](#faq)

## Overview

```js
npm i node-mac-permissions
```

This native Node.js module allows you to manage an app's access to:

- Apple Events
- Accessibility
- Calendar
- Camera
- Contacts
- Full Disk Access
- Input Monitoring
- Location
- Microphone
- Photos
- Protected Folders
- Reminders
- Screen Capture
- Speech Recognition
- Notifications

If you need to ask for permissions, your app must be allowed to ask for permission :

- For a Nodejs script/app, you can use a terminal app such as [iTerm2](https://iterm2.com/) (it won't work on macOS Terminal.app)
- For an Electron app (or equivalent), you'll have to update `Info.plist` to include a usage description key like `NSMicrophoneUsageDescription` for microphone permission.

If you're using macOS 12.3 or newer, you'll need to ensure you have Python installed on your system, as macOS does not bundle it anymore.

## API

### `permissions.getAuthStatus(type)`

- `type` String - The type of system component to which you are requesting access. Can be one of `accessibility`, `bluetooth`, `calendar`, `camera`, `contacts`, `full-disk-access`, `input-monitoring`, `location`, `microphone`,`notifications`, `photos`, `reminders`, `screen`, or `speech-recognition`.

Returns `String` - Can be one of `not determined`, `denied`, `authorized`, `limited`, `provisional`, or `restricted`.

Checks the authorization status of the application to access `type` on macOS.

Return Value Descriptions:

- `not determined` - The user has not yet made a choice regarding whether the application may access `type` data.
- `restricted` - The application is not authorized to access `type` data. The user cannot change this application’s status, possibly due to active restrictions such as parental controls being in place.
- `denied` - The user explicitly denied access to `type` data for the application.
- `authorized` - The application is authorized to access `type` data.
- `limited` - The application is authorized for limited access to `type` data. Currently only applicable to the `photos` type.
- 'provisional' - The application is provisionally authorized to access `type` data. Currently only applicable to the `notifications` type.

**Notes:**

- Access to `bluetooth` will always return a status of `authorized` prior to macOS 10.15, as the underlying API was not introduced until that version.
- Access to `camera` and `microphone` will always return a status of `authorized` prior to macOS 10.14, as the underlying API was not introduced until that version.
- Access to `input-monitoring` will always return a status of `authorized` prior to macOS 10.15, as the underlying API was not introduced until that version.
- Access to `music-library` will always return a status of `authorized` prior to macOS 11.0, as the underlying API was not introduced until that version.
- Access to `screen` will always return a status of `authorized` prior to macOS 10.15, as the underlying API was not introduced until that version.
- Access to `speech-recognition` will always return a status of `authorized` prior to macOS 10.15, as the underlying API was not introduced until that version.

Example:

```js
const types = [
  'accessibility',
  'bluetooth',
  'calendar',
  'camera',
  'contacts',
  'full-disk-access',
  'input-monitoring',
  'location',
  'microphone',
  'music-library',
  'notifications',
  'photos-add-only',
  'photos-read-write',
  'reminders',
  'speech-recognition',
  'screen',
]

for (const type of types) {
  const status = getAuthStatus(type)
  console.log(`Access to ${type} is ${status}`)
}
```

### `permissions.askForAccessibilityAccess()`

There is no API for programmatically requesting Accessibility access on macOS at this time, and so calling this method will trigger opening of System Preferences at the Accessibility pane of Security and Privacy.

Example:

```js
const { askForAccessibilityAccess } = require('node-mac-permissions')

askForAccessibilityAccess()
```

### `permissions.askForAppleEventsAccess(targetAppBundleId[, shouldPrompt])`

- `targetAppBundleId` String - The bundle identifier for the app you're targeting to send Apple Events to.
- `shouldPrompt` Boolean (optional) - If this is `false`, the current authorization status will be returned for the provided `targetAppBundleId`. Default is true.

Returns `Promise<String>` - Whether or not the request succeeded or failed; can be `authorized`, `denied`, or `not determined`.

Your app’s `Info.plist` file must provide a value for the `NSAppleEventsUsageDescription` key that explains to the user why your app is requesting the ability to send Apple Events.

```plist
<key>NSAppleEventsUsageDescription</key>
<string>Your reason for wanting the ability to send Apple Events</string>
```

Example:

```js
const { askForAppleEventsAccess } = require('node-mac-permissions')

askForAppleEventsAccess('com.apple.finder').then(status => {
  console.log(`Access to send Apple Events is ${status}`)
})
```

### `permissions.askForContactsAccess()`

Returns `Promise<String>` - Whether or not the request succeeded or failed; can be `authorized` or `denied`.

Your app’s `Info.plist` file must provide a value for the `NSContactsUsageDescription` key that explains to the user why your app is requesting Contacts access.

```plist
<key>NSContactsUsageDescription</key>
<string>Your reason for wanting to access the Contact store</string>
```

Example:

```js
const { askForContactsAccess } = require('node-mac-permissions')

askForContactsAccess().then(status => {
  console.log(`Access to Contacts is ${status}`)
})
```

### `permissions.askForCalendarAccess([accessLevel])`

- `accessLevel` String (optional) - The access level being requested of Photos. Can be either `write-only` or `full`. Default is `write-only`. Only available on macOS 14 or higher.

Returns `Promise<String>` - Whether or not the request succeeded or failed; can be `authorized` or `denied`.

Example:

```js
const { askForCalendarAccess } = require('node-mac-permissions')

askForCalendarAccess().then(status => {
  console.log(`Access to Calendar is ${status}`)
})
```

On macOS 14 and newer, your app’s `Info.plist` file must provide a value for either the `NSCalendarsWriteOnlyAccessUsageDescription` key or the `NSCalendarsFullAccessUsageDescription` key that explains to the user why your app is requesting Calendar access.

```plist
<key>NSCalendarsWriteOnlyAccessUsageDescription</key>
<string>Your reason for wanting write-only Calendar access</string>
```

```plist
<key>NSCalendarsFullAccessUsageDescription</key>
<string>Your reason for wanting full Calendar access</string>
```

### `permissions.askForSpeechRecognitionAccess()`

Returns `Promise<String>` - Whether or not the request succeeded or failed; can be `authorized`, `denied`, or `restricted`.

Checks the authorization status for Speech Recognition access. If the status check returns:

- `not determined` - The Speech Recognition access authorization will prompt the user to authorize or deny. The Promise is resolved after the user selection with either `authorized` or `denied`.
- `denied` - The `Security & Privacy` System Preferences window is opened with the Speech Recognition privacy key highlighted. On open of the `Security & Privacy` window, the Promise is resolved as `denied`.
- `restricted` - The Promise is resolved as `restricted`.

Your app must provide an explanation for its use of Speech Recognition using the `NSSpeechRecognitionUsageDescription` `Info.plist` key;

```plist
<key>NSSpeechRecognitionUsageDescription</key>
<string>Your reason for wanting to access Speech Recognition</string>
```

Example:

```js
const { askForSpeechRecognitionAccess } = require('node-mac-permissions')

askForSpeechRecognitionAccess().then(status => {
  console.log(`Access to Speech Recognition is ${status}`)
})
```

**Note:** `status` will be resolved back as `authorized` prior to macOS 10.15, as the underlying API was not introduced until that version.

### `permissions.askForRemindersAccess()`

Returns `Promise<String>` - Whether or not the request succeeded or failed; can be `authorized` or `denied`.

Example:

```js
const { askForRemindersAccess } = require('node-mac-permissions')

askForRemindersAccess().then(status => {
  console.log(`Access to Reminders is ${status}`)
})
```

On macOS 14 and newer, your app’s `Info.plist` file must provide a value for the `NSRemindersFullAccessUsageDescription` key that explains to the user why your app is
requesting Reminders access.

```plist
<key>NSRemindersFullAccessUsageDescription</key>
<string>Your reason for wanting access to read and write Reminders data.</string>
```

### `permissions.askForFoldersAccess(folder)`

- `type` String - The folder to which you are requesting access. Can be one of `desktop`, `documents`, or `downloads`.

Returns `Promise<String>` - Whether or not the request succeeded or failed; can be `authorized` or `denied`.

Example:

```js
const { askForFoldersAccess } = require('node-mac-permissions')

askForFoldersAccess('desktop').then(status => {
  console.log(`Access to Desktop is ${status}`)
})
```

```plist
<key>NSDesktopFolderUsageDescription</key>
<string>Your reason for wanting to access the Desktop folder</string>
```

```plist
<key>NSDocumentsFolderUsageDescription</key>
<string>Your reason for wanting to access the Documents folder</string>
```

```plist
<key>NSDownloadsFolderUsageDescription</key>
<string>Your reason for wanting to access the Downloads folder</string>
```

### `permissions.askForFullDiskAccess()`

There is no API for programmatically requesting Full Disk Access on macOS at this time, and so calling this method will trigger opening of System Preferences at the Full Disk pane of Security and Privacy.

Example:

```js
const { askForFullDiskAccess } = require('node-mac-permissions')

askForFullDiskAccess()
```

If you would like your app to pop up a dialog requesting full disk access when your app attempts to access protected resources, you should add the `NSSystemAdministrationUsageDescription` key to your `Info.plist`:

```plist
<key>NSSystemAdministrationUsageDescription</key>
<string>Your reason for wanting Full Disk Access</string>
```

### `permissions.askForCameraAccess()`

Returns `Promise<String>` - Current permission status; can be `authorized`, `denied`, or `restricted`.

Checks the authorization status for camera access. If the status check returns:

- `not determined` - The camera access authorization will prompt the user to authorize or deny. The Promise is resolved after the user selection with either `authorized` or `denied`.
- `denied` - The `Security & Privacy` System Preferences window is opened with the Camera privacy key highlighted. On open of the `Security & Privacy` window, the Promise is resolved as `denied`.
- `restricted` - The Promise is resolved as `restricted`.

Your app must provide an explanation for its use of capture devices using the `NSCameraUsageDescription` `Info.plist` key; Calling this method or attempting to start a capture session without a usage description raises an exception.

```plist
<key>NSCameraUsageDescription</key>
<string>Your reason for wanting to access the Camera</string>
```

**Note:**

- `status` will be resolved back as `authorized` prior to macOS 10.14, as the underlying API was not introduced until that version.

Example:

```js
const { askForCameraAccess } = require('node-mac-permissions')

askForCameraAccess().then(status => {
  console.log(`Access to Camera is ${status}`)
})
```

### `permissions.askForLocationAccess([accessLevel])`

- `accessLevel` String (optional) - The access level being requested of Location. Can be either `always` or `when-in-use`. Default is `when-in-use`. Only available on macOS 10.15 or higher.

Returns `void`.

Checks the authorization status for input monitoring access. If the status check returns:

- `not determined` - A dialog will be displayed directing the user to the `Locatiojn` System Preferences window , where the user can approve your app to access location events in the background.
- `denied` - The `Location` System Preferences window is opened with the Location privacy key highlighted.

Your app must provide an explanation for its use of location using either the `NSLocationUsageDescription` `Info.plist` key; Calling this method or attempting to access location without a usage description raises an exception.

```plist
<key>NSLocationUsageDescription</key>
<string>Your reason for wanting to access the user's Location</string>
```

### `permissions.askForInputMonitoringAccess([accessLevel])`

- `accessLevel` String (optional) - The access level being requested of Input Monitoring. Can be either `post` or `listen`. Default is `listen` Only available on macOS 10.15 or higher.

Returns `Promise<String>` - Current permission status; can be `authorized` or `denied`.

Checks the authorization status for input monitoring access. If the status check returns:

- `not determined` - A dialog will be displayed directing the user to the `Security & Privacy` System Preferences window , where the user can approve your app to monitor keyboard events in the background. The Promise is resolved as `denied`.
- `denied` - The `Security & Privacy` System Preferences window is opened with the Input Monitoring privacy key highlighted. On open of the `Security & Privacy` window, the Promise is resolved as `denied`.

**Note:**

- `status` will be resolved back as `authorized` prior to macOS 10.15, as the underlying API was not introduced until that version.

Example:

```js
const { askForInputMonitoringAccess } = require('node-mac-permissions')

askForInputMonitoringAccess().then(status => {
  console.log(`Access to Input Monitoring is ${status}`)
})
```

### `permissions.askForMicrophoneAccess()`

Returns `Promise<String>` - Current permission status; can be `authorized`, `denied`, or `restricted`.

Checks the authorization status for microphone access. If the status check returns:

- `not determined` - The microphone access authorization will prompt the user to authorize or deny. The Promise is resolved after the user selection with either `authorized` or `denied`.
- `denied` - The `Security & Privacy` System Preferences window is opened with the Microphone privacy key highlighted. On open of the `Security & Privacy` window, the Promise is resolved as `denied`.
- `restricted` - The Promise is resolved as `restricted`.

Your app must provide an explanation for its use of capture devices using the `NSMicrophoneUsageDescription` `Info.plist` key; Calling this method or attempting to start a capture session without a usage description raises an exception.

```plist
<key>NSMicrophoneUsageDescription</key>
<string>Your reason for wanting to access the Microphone</string>
```

**Note:**

- `status` will be resolved back as `authorized` prior to macOS 10.14, as the underlying API was not introduced until that version.

Example:

```js
const { askForMicrophoneAccess } = require('node-mac-permissions')

askForMicrophoneAccess().then(status => {
  console.log(`Access to Microphone is ${status}`)
})
```

### `permissions.askForMusicLibraryAccess()`

Returns `Promise<String>` - Whether or not the request succeeded or failed; can be `authorized`, `denied`, or `restricted`.

- `not determined` - The Music Library access authorization will prompt the user to authorize or deny. The Promise is resolved after the user selection with either `authorized` or `denied`.
- `denied` - The `Security & Privacy` System Preferences window is opened with the Music Library privacy key highlighted. On open of the `Security & Privacy` window, the Promise is resolved as `denied`.
- `restricted` - The Promise is resolved as `restricted`.

Your app must provide an explanation for its use of the music library using the `NSAppleMusicUsageDescription` `Info.plist` key.

```plist
<key>NSAppleMusicUsageDescription</key>
<string>Your reason for wanting to access the user’s media library.</string>
```

**Note:**

- `status` will be resolved back as `authorized` prior to macOS 11.0, as the underlying API was not introduced until that version.

Example:

```js
const { askForMusicLibraryAccess } = require('node-mac-permissions')

askForMusicLibraryAccess().then(status => {
  console.log(`Access to Apple Music Library is ${status}`)
})
```

### `permissions.askForPhotosAccess([accessLevel])`

- `accessLevel` String (optional) - The access level being requested of Photos. Can be either `add-only` or `read-write`. Default is `add-only`. Only available on macOS 11 or higher.

Returns `Promise<String>` - Current permission status; can be `authorized`, `denied`, or `restricted`.

Checks the authorization status for Photos access. If the status check returns:

- `not determined` - The Photos access authorization will prompt the user to authorize or deny. The Promise is resolved after the user selection with either `authorized` or `denied`.
- `denied` - The `Security & Privacy` System Preferences window is opened with the Photos privacy key highlighted. On open of the `Security & Privacy` window, the Promise is resolved as `denied`.
- `restricted` - The Promise is resolved as `restricted`.

Your app must provide an explanation for its use of the photo library using either the `NSPhotoLibraryUsageDescription` or the `NSPhotoLibraryAddUsageDescription` `Info.plist` key.

For requesting add-only access to the user’s photo library:

```plist
<key>NSPhotoLibraryAddUsageDescription</key>
<string>Your reason for wanting to access Photos</string>
```

For requesting read/write access to the user’s photo library:

```plist
<key>NSPhotoLibraryUsageDescription</key>
<string>Your reason for wanting to access Photos</string>
```

**Note:**

You should add the `PHPhotoLibraryPreventAutomaticLimitedAccessAlert` key with a Boolean value of `YES` to your app’s `Info.plist` file to prevent the system from automatically presenting the limited library selection prompt. See [`PHAuthorizationStatusLimited`](https://developer.apple.com/documentation/photokit/phauthorizationstatus/phauthorizationstatuslimited?language=objc) for more information.

Example:

```js
const { askForPhotosAccess } = require('node-mac-permissions')

askForPhotosAccess().then(status => {
  console.log(`Access to Photos is ${status}`)
})
```

### `permissions.askForScreenCaptureAccess([openPreferences])`

- `openPreferences` Boolean (optional) - Whether to open System Preferences if the request to authorize Screen Capture fails or is denied by the user.

Calling this method for the first time within an app session will trigger a permission modal. If this modal is denied, there is no  API for programmatically requesting Screen Capture on macOS at this time. Calling this method after denial with `openPreferences = true` will trigger opening of System Preferences at the Screen Capture pane of Security and Privacy.

Example:

```js
const { askForScreenCaptureAccess } = require('node-mac-permissions')

askForScreenCaptureAccess()
```

## FAQ

Q. I'm seeing an error like the following when using webpack:

```sh
App threw an error during load
TypeError: Cannot read property 'indexOf' of undefined
    at Function.getFileName (webpack-internal:///./node_modules/bindings/bindings.js:178:16)
```

A. This error means that webpack packed this module, which it should not. To fix this, you should configure webpack to use this module externally, e.g explicitly not pack it.

----------------------

Q. I've authorized access to a particular system component and want to reset it. How do I do that?

A. You can use `tccutil` to do this!

The `tccutil` command manages the privacy database, which stores decisions the user has made about whether apps may access personal data.

Examples:

```sh
# Reset all app permissions
$ tccutil reset All

# Reset Accessibility access permissions
$ tccutil reset Accessibility

# Reset Reminders access permissions
$ tccutil reset Reminders

# Reset Calendar access permissions
$ tccutil reset Calendar

# Reset Camera access permissions
$ tccutil reset Camera

# Reset Microphone access permissions
$ tccutil reset Microphone

# Reset Photos access permissions
$ tccutil reset Photos

# Reset Screen Capture access permissions
$ tccutil reset ScreenCapture

# Reset Full Disk Access permissions
$ tccutil reset SystemPolicyAllFiles

# Reset Contacts permissions
$ tccutil reset AddressBook

# Reset Desktop folder access
$ tccutil reset SystemPolicyDesktopFolder <bundleID>

# Reset Documents folder access
$ tccutil reset SystemPolicyDocumentsFolder <bundleID>

# Reset Downloads folder access
$ tccutil reset SystemPolicyDownloadsFolder <bundleID>

# Reset Removable Volumes access
$ tccutil reset SystemPolicyRemovableVolumes <bundleID>
```