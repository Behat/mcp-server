# Behat MCP Server

An [MCP (Model Context Protocol)](https://modelcontextprotocol.io/) server for the [Behat](https://behat.org) BDD testing framework. This server allows AI assistants (such as Claude, Cursor, or other MCP-compatible clients) to run Behat tests directly.

> **Note:** This MCP server currently does not run on Windows due to PHP's limitations with non-blocking pipes.

## Installation

Install via Composer:

```bash
composer require behat/mcp-server
```

## Starting the MCP Server

The MCP server can be started using the `behat-mcp-server` command:

```bash
vendor/bin/behat-mcp-server
```

### Command Line Options

| Option | Short | Description | Default |
|--------|-------|-------------|---------|
| `--transport` | `-t`  | Transport type (`stdio` or `http`) | `stdio` |
| `--host` | `-o`  | Host for HTTP transport | `127.0.0.1` |
| `--port` | `-p`  | Port for HTTP transport | `8080` |

### Examples

Start with stdio transport (default):

```bash
vendor/bin/behat-mcp-server
```

Start with HTTP transport:

```bash
vendor/bin/behat-mcp-server --transport=http --host=127.0.0.1 --port=8080
```

## Available Tools

### `run-behat-tests`

Runs Behat BDD tests in the current project.

#### Parameters

| Parameter | Type  | Required | Description |
|-----------|-------|----------|-------------|
| `config` | string | No | Path to a Behat configuration file |
| `profile` | string | No | Name of a profile to use |
| `suite` | string | No | Name of a suite to use |
| `paths` | array | No | List of paths to execute (features or scenarios) |
| `additionalOptions` | array | No | Additional command-line options as key-value pairs |

## Configuring AI Agents

### Stdio Transport

The stdio transport is the recommended method for local development. The MCP server communicates via standard input/output streams.

Add this to your configuration file:

```json
{
  "mcpServers": {
    "behat": {
      "command": "php",
      "args": ["/path/to/your/project/vendor/bin/behat-mcp-server"],
      "cwd": "/path/to/your/project"
    }
  }
}
```

### HTTP Transport

The HTTP transport is useful for remote servers or when using Docker.

Start the MCP server using the HTTP transport as described above and add this to your configuration file
(using the host and port that you used when starting the server):


```json
{
  "mcpServers": {
    "behat": {
      "url": "http://127.0.0.1:8080/mcp"
    }
  }
}
```

## License

MIT License. See [LICENSE](LICENSE) for details.
