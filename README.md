[![Build Status](https://travis-ci.org/VISIT-X/arc-jest.svg?branch=master)](https://travis-ci.org/VISIT-X/arc-jest)


# arc-jest
Arcanist/Phabricator unit test engine for Jest

### Install & configuration

1. Install the package via npm:

```
npm install arc-jest jest
```

### Config options

| Property | Description | Default | Since version |
| ---- | ---- | ---- | ---- |
| `include` | Paths that should be used for tests detection | `""` | `0.0.1` | 
| `test.dirs` | Same as `include` (old implementation) | `"./src"` |`0.0.1` |
| `bin` | Binary that should be used to run tests | `/node_modules/.bin/jest` | `0.0.2` | 
| `forceAll` | Force all tests to run on each diff | `fasle` | `0.0.5` | 

#### Sample .arcconfig

```json
{
    "project_id": "YourProjectName",
    "load" : ["./node_modules/arc-jest"],
    "unit.engine": "JestUnitTestEngine",
    "jest": {
        "forceAll": true,
        "bin": "npm run jest",
        "include": "/src/app/{components,containers,utils}",
        "test.dirs": [
            "/tests/jest"
        ]
    }
}
```
