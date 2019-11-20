# arc-jest
Arcanist/Phabricator unit test engine for Jest

### Install & configuration

1. Install the package via npm:

```
npm install arc-jest jest
```

#### Sample .arcconfig

```json
{
    "project_id": "YourProjectName",
    "load" : ["./node_modules/arc-jest"],
    "unit.engine": "JestUnitTestEngine",
    "jest": {
        "include": "/src/app/{components,containers,utils}",
        "test.dirs": [
            "/tests/jest"
        ]
    }
}
```
