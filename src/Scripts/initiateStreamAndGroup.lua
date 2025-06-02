-- Redis/Dragonfly Lua script for creating consumer group in stream
-- Usage: EVAL script 2 streamName groupName

local streamName = KEYS[1]
local groupName = KEYS[2]

-- Validate input parameters
if not streamName or streamName == "" then
    return {0, "Stream name is required and cannot be empty"}
end

if not groupName or groupName == "" then
    return {0, "Group name is required and cannot be empty"}
end

-- Try to create group directly first (most common case)
local createResult = redis.pcall('XGROUP', 'CREATE', streamName, groupName, '$', 'MKSTREAM')

local groupMessage = ""
-- If successful, we're done with group creation
if not createResult.err then
    groupMessage = "Group created successfully"
elseif string.find(createResult.err, "BUSYGROUP") then
    -- Group already exists, that's also success
    groupMessage = "Group already exists"
else
    -- Any other error is a real error
    return {0, "Failed to create group: " .. createResult.err}
end

return {1, groupMessage}