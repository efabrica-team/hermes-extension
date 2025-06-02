-- Redis/Dragonfly Lua script for creating consumer groups and consumers in streams
-- Usage: EVAL script 3 streamName groupName consumerName

local streamName = KEYS[1]
local groupName = KEYS[2]
local consumerName = KEYS[3]

-- Validate input parameters
if not streamName or streamName == "" then
    return {0, "Stream name is required and cannot be empty"}
end

if not groupName or groupName == "" then
    return {0, "Group name is required and cannot be empty"}
end

if not consumerName or consumerName == "" then
    return {0, "Consumer name is required and cannot be empty"}
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

-- Now create the consumer
local consumerResult = redis.pcall('XGROUP', 'CREATECONSUMER', streamName, groupName, consumerName)

if consumerResult.err then
    return {0, "Group ready but failed to create consumer: " .. consumerResult.err}
end

-- consumerResult contains 1 (created) or 0 (already existed)
if consumerResult == 1 then
    return {1, groupMessage .. ", consumer created successfully"}
else
    return {1, groupMessage .. ", consumer already exists"}
end